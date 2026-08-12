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
        $this->sendReply($replyToken, [[
            'type' => 'text',
            'text' => mb_substr($text, 0, 5000),
        ]]);
    }

    public function replyImageWithLink(
        string $replyToken,
        string $baseUrl,
        string $linkUrl,
    ): void {
        $this->sendReply($replyToken, $this->imageMessages($baseUrl, $linkUrl));
    }

    public function push(string $to, string $text): void
    {
        $this->sendPush($to, [[
            'type' => 'text',
            'text' => mb_substr($text, 0, 5000),
        ]]);
    }

    public function pushImageWithLink(
        string $to,
        string $baseUrl,
        string $linkUrl,
    ): void {
        $this->sendPush($to, $this->imageMessages($baseUrl, $linkUrl));
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

    /** @param array<int, array<string, mixed>> $messages */
    private function sendReply(string $replyToken, array $messages): void
    {
        $this->client()
            ->post((string) config('services.line.reply_url'), [
                'replyToken' => $replyToken,
                'messages' => $messages,
            ])
            ->throw();
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function sendPush(string $to, array $messages): void
    {
        $this->client()
            ->post((string) config('services.line.push_url'), [
                'to' => $to,
                'messages' => $messages,
            ])
            ->throw();
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $accessToken = (string) config('services.line.channel_access_token');

        if ($accessToken === '') {
            throw new RuntimeException('LINE channel access token is not configured.');
        }

        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10);
    }
}
