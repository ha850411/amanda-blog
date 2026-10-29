<?php

namespace App\Services;

use JsonException;
use RuntimeException;

class StakeApiSourceConfig
{
    public function load(): ?array
    {
        $path = trim((string) config('services.stake.sources_file'));
        if ($path === '') {
            return null;
        }

        $path = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Stake source configuration file is not readable.');
        }

        try {
            $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stake source configuration must be valid JSON.');
        }

        if (! is_array($config) || ! is_array($config['sources'] ?? null)
            || ! array_is_list($config['sources']) || $config['sources'] === []) {
            throw new RuntimeException('Stake source configuration requires an ordered sources list.');
        }

        foreach (['timeout_seconds', 'connect_timeout_seconds'] as $key) {
            if (! is_int($config[$key] ?? null) || $config[$key] < 1 || $config[$key] > 60) {
                throw new RuntimeException('Stake source timeouts must be integers between 1 and 60 seconds.');
            }
        }

        $config['failure_cooldown_seconds'] ??= 60;
        if (! is_int($config['failure_cooldown_seconds'])
            || $config['failure_cooldown_seconds'] < 0 || $config['failure_cooldown_seconds'] > 300) {
            throw new RuntimeException('Stake source failure cooldown must be an integer between 0 and 300 seconds.');
        }

        if (! is_string($config['user_agent'] ?? null) || trim($config['user_agent']) === ''
            || str_contains($config['user_agent'], "\r") || str_contains($config['user_agent'], "\n")) {
            throw new RuntimeException('Stake source configuration requires a valid user agent.');
        }

        $sources = [];
        foreach ($config['sources'] as $source) {
            if (! is_array($source) || ! is_string($source['name'] ?? null)
                || ! preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $source['name'])
                || ! is_string($source['url'] ?? null) || ! array_key_exists('proxy', $source)) {
                throw new RuntimeException('Each Stake source requires a name, URL and proxy setting.');
            }

            $url = parse_url($source['url']);
            if (! filter_var($source['url'], FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https'
                || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])
                || ! in_array($url['path'] ?? '', ['/_api/graphql', '/_api/v1/graphql'], true)) {
                throw new RuntimeException('Stake sources must use HTTPS GraphQL URLs without credentials or query parameters.');
            }

            $usesProxy = $source['proxy'] !== null;
            $proxy = $usesProxy ? $this->resolveProxy($source['proxy']) : null;
            $sources[] = [
                'name' => $source['name'],
                'url' => $source['url'],
                'proxy' => $proxy,
                'uses_proxy' => $usesProxy,
                'available' => ! $usesProxy || $proxy !== null,
            ];
        }

        return array_replace($config, ['sources' => $sources]);
    }

    private function resolveProxy(mixed $definition): ?string
    {
        if (! is_array($definition) || ($definition['url'] ?? '') !== 'http://p.webshare.io:80'
            || ($definition['credentials_from'] ?? '') !== 'STAKE_PROXY'
            || ! is_int($definition['username_index'] ?? null)
            || $definition['username_index'] < 1 || $definition['username_index'] > 10) {
            throw new RuntimeException('Stake fallback proxy requires a Webshare endpoint and an index from 1 to 10.');
        }

        // Read through Laravel config so this also works with config:cache.
        $credentials = parse_url((string) config('services.stake.proxy'));
        $username = rawurldecode((string) ($credentials['user'] ?? ''));
        $password = rawurldecode((string) ($credentials['pass'] ?? ''));
        if ($username === '' || $password === '') {
            return null;
        }

        $username = preg_replace('/-\d+$/', '', $username).'-'.$definition['username_index'];

        return 'http://'.rawurlencode($username).':'.rawurlencode($password).'@p.webshare.io:80';
    }
}
