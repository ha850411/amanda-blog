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
        $this->send($replyToken, [[
            'type' => 'text',
            'text' => mb_substr($text, 0, 5000),
        ]]);
    }

    public function replyImagemap(
        string $replyToken,
        string $baseUrl,
        string $altText,
        string $linkUrl,
    ): void {
        $this->send($replyToken, [[
            'type' => 'imagemap',
            'baseUrl' => $baseUrl,
            'altText' => mb_substr($altText, 0, 400),
            'baseSize' => [
                'width' => 1040,
                'height' => 1040,
            ],
            'actions' => [[
                'type' => 'uri',
                'linkUri' => $linkUrl,
                'area' => [
                    'x' => 0,
                    'y' => 0,
                    'width' => 1040,
                    'height' => 1040,
                ],
            ]],
        ]]);
    }

    /** @param  array<int, array<string, mixed>>  $messages */
    private function send(string $replyToken, array $messages): void
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
                'messages' => $messages,
            ])
            ->throw();
    }
}
