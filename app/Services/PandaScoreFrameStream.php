<?php

namespace App\Services;

use WebSocket\Client;
use WebSocket\Configuration;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

class PandaScoreFrameStream
{
    /** @return array<string, mixed>|null */
    public function firstFrame(string $url, string $token): ?array
    {
        if (! $this->isAllowedUrl($url)) {
            return null;
        }

        $url .= (str_contains($url, '?') ? '&' : '?').'token='.rawurlencode($token);
        $timeout = max(1.0, (float) config('services.pandascore.frame_timeout_seconds', 3));
        $deadline = microtime(true) + $timeout;
        $configuration = new Configuration(timeout: $timeout);
        $client = new Client($url, $configuration);
        $client
            ->addMiddleware(new CloseHandler)
            ->addMiddleware(new PingResponder);

        try {
            // PandaScore sends a hello message first on some connections. Read
            // a small bounded number of messages and return the first full LoL frame.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $remaining = $deadline - microtime(true);

                if ($remaining <= 0) {
                    return null;
                }

                $client->setTimeout(max(0.1, $remaining));
                $message = $client->receive();
                $payload = json_decode($message->getContent(), true);

                if (is_array($payload) && is_array($payload['payload'] ?? null)) {
                    $payload = $payload['payload'];
                }

                if (is_array($payload)
                    && is_array($payload['blue'] ?? null)
                    && is_array($payload['red'] ?? null)) {
                    return $payload;
                }
            }

            return null;
        } finally {
            $client->disconnect();
        }
    }

    private function isAllowedUrl(string $url): bool
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'wss'
            && mb_strtolower((string) parse_url($url, PHP_URL_HOST)) === 'live.pandascore.co';
    }
}
