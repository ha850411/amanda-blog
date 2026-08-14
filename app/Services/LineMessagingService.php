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

    /** @return array{status: int, request_id: ?string} */
    public function reply(string $replyToken, string $text): array
    {
        return $this->sendReply($replyToken, $this->textMessages($text));
    }

    /** @return array{status: int, request_id: ?string} */
    public function replyImageWithLink(
        string $replyToken,
        string $baseUrl,
        string $linkUrl,
    ): array {
        return $this->sendReply($replyToken, $this->imageMessages($baseUrl, $linkUrl));
    }

    /** @return array{status: int, request_id: ?string} */
    public function push(string $to, string $text): array
    {
        return $this->sendPush($to, $this->textMessages($text));
    }

    /** @return array{status: int, request_id: ?string} */
    public function pushImageWithLink(
        string $to,
        string $baseUrl,
        string $linkUrl,
    ): array {
        return $this->sendPush($to, $this->imageMessages($baseUrl, $linkUrl));
    }

    /** @return array<int, array<string, mixed>> */
    private function textMessages(string $text): array
    {
        return [[
            'type' => 'text',
            'text' => mb_substr($text, 0, 5000),
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function imageMessages(string $baseUrl, string $linkUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        return [
            [
                'type' => 'image',
                'originalContentUrl' => $baseUrl.'/1040',
                'previewImageUrl' => $baseUrl.'/700',
            ],
            [
                'type' => 'text',
                'text' => mb_substr('完整賽程｜'.$linkUrl, 0, 5000),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{status: int, request_id: ?string}
     */
    private function sendReply(string $replyToken, array $messages): array
    {
        return $this->send((string) config('services.line.reply_url', 'https://api.line.me/v2/bot/message/reply'), [
            'replyToken' => $replyToken,
            'messages' => $messages,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{status: int, request_id: ?string}
     */
    private function sendPush(string $to, array $messages): array
    {
        if ($to === '') {
            throw new RuntimeException('LINE push target is not available.');
        }

        return $this->send((string) config('services.line.push_url', 'https://api.line.me/v2/bot/message/push'), [
            'to' => $to,
            'messages' => $messages,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, request_id: ?string}
     */
    private function send(string $url, array $payload): array
    {
        $accessToken = (string) config('services.line.channel_access_token');

        if ($accessToken === '') {
            throw new RuntimeException('LINE channel access token is not configured.');
        }

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post($url, $payload)
            ->throw();

        return [
            'status' => $response->status(),
            'request_id' => $response->header('x-line-request-id'),
        ];
    }
}
