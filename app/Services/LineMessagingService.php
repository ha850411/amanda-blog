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
        ?string $linkUrl = null,
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
        ?string $linkUrl = null,
    ): array {
        return $this->sendPush($to, $this->imageMessages($baseUrl, $linkUrl));
    }

    /** @return array<int, array<string, mixed>> */
    private function textMessages(string $text): array
    {
        // Detailed schedule histories can exceed one text bubble. LINE accepts
        // up to five messages, each limited to 5,000 UTF-16 code units.
        // https://developers.line.biz/en/docs/messaging-api/text-character-count/
        $messages = [];
        do {
            $chunk = $this->textPrefix($text, 5000);
            $length = mb_strlen($chunk);
            if (mb_strlen($text) > $length) {
                $lineEnd = mb_strrpos($chunk, "\n");
                if ($lineEnd !== false && $lineEnd > 0) {
                    $length = $lineEnd + 1;
                    $chunk = mb_substr($chunk, 0, $length);
                }
            }
            $messages[] = ['type' => 'text', 'text' => $chunk];
            $text = mb_substr($text, $length);
        } while ($text !== '' && count($messages) < 5);

        if ($text !== '') {
            $notice = "\n內容過長，部分內容省略；請縮小查詢範圍。";
            $last = array_key_last($messages);
            $messages[$last]['text'] = $this->textPrefix($messages[$last]['text'], 5000 - mb_strlen($notice)).$notice;
        }

        return $messages;
    }

    private function textPrefix(string $text, int $maxUnits): string
    {
        $prefix = mb_substr($text, 0, $maxUnits);
        while (($units = strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')) / 2) > $maxUnits) {
            // Unicode supplementary characters use two UTF-16 units. Trim by
            // complete Unicode characters so emoji are never split in half.
            $prefix = mb_substr($prefix, 0, mb_strlen($prefix) - (int) ceil(($units - $maxUnits) / 2));
        }

        return $prefix;
    }

    /** @return array<int, array<string, mixed>> */
    private function imageMessages(string $baseUrl, ?string $linkUrl = null): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        return [
            [
                'type' => 'image',
                'originalContentUrl' => $baseUrl.'/1440',
                'previewImageUrl' => $baseUrl.'/700',
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
