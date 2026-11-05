<?php

namespace App\Services;

use App\Exceptions\StakeApiRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class StakeApiClient
{
    public function query(string $operationName, string $query, array $variables): array
    {
        $sources = app(StakeApiSourceConfig::class)->load();
        if ($sources === null) {
            return $this->sendGraphQLRequestToSource($operationName, $query, $variables);
        }

        $lastException = null;
        $health = app(StakeApiSourceHealth::class);
        $cooldown = $sources['failure_cooldown_seconds'];
        $skippedCoolingSources = false;
        foreach ($sources['sources'] as $source) {
            if (! $source['available']) {
                Log::warning('Stake API source skipped: proxy credentials are not configured.', [
                    'source' => $source['name'],
                ]);

                continue;
            }

            if ($health->isCoolingDown($source, $cooldown)) {
                $skippedCoolingSources = true;

                continue;
            }

            try {
                return $this->sendGraphQLRequestToSource($operationName, $query, $variables, $source, $sources);
            } catch (RequestException|ConnectionException $exception) {
                $lastException = $exception;
                $status = $exception instanceof RequestException ? $exception->response->status() : null;
                Log::warning('Stake API source failed.', [
                    'operation' => $operationName,
                    'source' => $source['name'],
                    'via_proxy' => $source['uses_proxy'],
                    'status' => $status,
                    'type' => $exception::class,
                ]);

                // Invalid credentials or a rejected query need a caller-side fix.
                if ($status === 401 || ($status === 400 && is_array($exception->response->json()))) {
                    throw $exception;
                }

                // Query-specific GraphQL errors must not disable unrelated queries.
                if ($exception instanceof ConnectionException || $status !== 200
                    || $this->isCloudflareChallenge($exception->response)
                    || ! is_array($exception->response->json())) {
                    $health->markFailure($source, $cooldown);
                }
            }
        }

        if ($lastException instanceof ConnectionException) {
            throw new ConnectionException('All configured Stake API sources failed to connect.');
        }

        if ($lastException === null && $skippedCoolingSources) {
            throw new ConnectionException('All configured Stake API sources are temporarily cooling down.');
        }

        throw $lastException ?? new RuntimeException('No usable Stake API sources are configured.');
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function sendGraphQLRequestToSource(string $operationName, string $query, array $variables, ?array $source = null, ?array $sources = null): array
    {
        $accessToken = trim((string) config('services.stake.access_token'));
        $apiUrl = $source['url'] ?? (string) config('services.stake.api_url', 'https://stake.com/_api/graphql');
        $timeout = $sources['timeout_seconds'] ?? (int) config('services.stake.timeout_seconds', 10);
        $language = (string) config('services.stake.language', 'zh');

        $headers = [
            'Accept' => '*/*',
            'Content-Type' => 'application/json',
            'x-access-token' => $accessToken,
            'x-language' => $language,
            'x-operation-name' => $operationName,
            'x-operation-type' => 'query',
        ];

        if ($source === null && ($cd = config('services.stake.kpsdk_cd'))) {
            $headers['x-kpsdk-cd'] = (string) $cd;
        }
        if ($source === null && ($ct = config('services.stake.kpsdk_ct'))) {
            $headers['x-kpsdk-ct'] = (string) $ct;
        }
        if ($source === null && ($h = config('services.stake.kpsdk_h'))) {
            $headers['x-kpsdk-h'] = (string) $h;
        }
        if ($source === null && ($v = config('services.stake.kpsdk_v'))) {
            $headers['x-kpsdk-v'] = (string) $v;
        }
        if ($source === null && ($cookie = config('services.stake.cookie'))) {
            $headers['Cookie'] = (string) $cookie;
        }

        if ($source !== null) {
            $origin = 'https://'.parse_url($apiUrl, PHP_URL_HOST);
            $headers['Origin'] = $origin;
            $headers['Referer'] = $origin.'/';
            $headers['x-stake-client-id'] = 'stake-web';
            $headers['x-stake-client-platform'] = 'web';
        }

        $userAgent = (string) (config('services.stake.user_agent') ?: ($sources['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));

        $request = Http::acceptJson()
            ->withHeaders($headers)
            ->withUserAgent($userAgent)
            ->timeout($timeout);

        if ($source !== null) {
            $request = $request->connectTimeout($sources['connect_timeout_seconds'])
                ->withOptions([
                    'allow_redirects' => false,
                    'proxy' => $source['proxy'] ?? '',
                    'curl' => $source['uses_proxy']
                        ? [CURLOPT_NOPROXY => '']
                        : [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'],
                ]);
        } elseif ($proxy = config('services.stake.proxy')) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        $maxRetries = $source === null
            ? (int) (config('services.stake.max_retries') ?? config('services.stake.retry_times') ?? 3)
            : 0;
        $retryDelayMs = (int) config('services.stake.retry_delay_ms', 100);

        if ($maxRetries > 0) {
            $attempt = 0;
            $request = $request->retry(
                $maxRetries + 1,
                $retryDelayMs,
                function (Throwable $exception) use ($operationName, $maxRetries, &$attempt): bool {
                    $attempt++;

                    if ($exception instanceof RequestException && $exception->response) {
                        if ($this->isCloudflareChallenge($exception->response)) {
                            return false;
                        }

                        $status = $exception->response->status();
                        if (in_array($status, [400, 401, 451], true)) {
                            return false;
                        }

                        if ($attempt <= $maxRetries) {
                            Log::warning('Stake API request failed, retrying...', [
                                'operation' => $operationName,
                                'status' => $status,
                                'attempt' => $attempt,
                                'max_retries' => $maxRetries,
                            ]);
                        }

                        return true;
                    }

                    if ($attempt <= $maxRetries) {
                        Log::warning('Stake API connection failed, retrying...', [
                            'operation' => $operationName,
                            'attempt' => $attempt,
                            'max_retries' => $maxRetries,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    return true;
                }
            );
        }

        $payload = [
            'query' => $query,
            'variables' => $variables === [] ? (object) [] : $variables,
            'operationName' => $operationName,
        ];

        $response = $request->post($apiUrl, $payload);

        if (! $response->successful()) {
            throw $source === null ? new RequestException($response) : new StakeApiRequestException($response);
        }

        $json = $response->json();

        if (! is_array($json) || ($source !== null
            && ($this->isCloudflareChallenge($response) || ! $this->isUsableGraphQLResponse($operationName, $json)))) {
            throw $source === null ? new RequestException($response) : new StakeApiRequestException($response);
        }

        return $json;
    }

    private function isUsableGraphQLResponse(string $operationName, array $json): bool
    {
        if (! empty($json['errors']) || ! is_array($json['data'] ?? null)) {
            return false;
        }

        if ($operationName === 'StakeOddsFixtures') {
            $sport = $json['data']['slugSport'] ?? null;

            return is_array($sport)
                && is_int($sport['upcomingCount'] ?? null) && is_int($sport['liveCount'] ?? null)
                && is_array($sport['upcoming'] ?? null) && is_array($sport['live'] ?? null);
        }

        if (in_array($operationName, ['StakeFixtureOdds', 'StakeFixtureOddsBySlug'], true)) {
            return array_key_exists('sportFixture', $json['data'])
                && ($json['data']['sportFixture'] === null
                    || (is_array($json['data']['sportFixture']) && is_array($json['data']['sportFixture']['groups'] ?? null)));
        }

        $user = $json['data']['user'] ?? null;
        if (! empty($json['errors']) || ! is_array($user) || $user === []) {
            return false;
        }

        return match ($operationName) {
            'ActiveBetsOverview' => is_int($user['activeSportBetCount'] ?? null)
                && is_array($user['activeSportBets'] ?? null)
                && is_array($user['balances'] ?? null),
            'ActiveBetCount_User' => is_int($user['activeSportBetCount'] ?? null),
            'UserBalances' => is_array($user['balances'] ?? null),
            'FetchActiveSportBets' => is_array($user['activeSportBets'] ?? null),
            'FetchSportBetList' => is_array($user['sportBetList'] ?? null),
            default => true,
        };
    }

    private function isCloudflareChallenge(Response $response): bool
    {
        return strtolower(trim((string) $response->header('cf-mitigated'))) === 'challenge';
    }
}
