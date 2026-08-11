<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LineMessagingService
{
    public function isValidSignature(string $body, ?string $signature): bool
    {
        $secret = (string) config('services.line.channel_secret');

        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return hash_equals($expected, $signature);
    }

    public function reply(string $replyToken, string $text): void
    {
        $accessToken = (string) config('services.line.channel_access_token');

        if ($accessToken === '') {
            throw new RuntimeException('LINE channel access token is not configured.');
        }

        Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post((string) config('services.line.reply_url'), [
                'replyToken' => $replyToken,
                'messages' => [[
                    'type' => 'text',
                    'text' => mb_substr($text, 0, 5000),
                ]],
            ])
            ->throw();
    }
}
