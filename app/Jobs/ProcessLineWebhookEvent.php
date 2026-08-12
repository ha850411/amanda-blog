<?php

namespace App\Jobs;

use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessLineWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 50;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $event */
    public function __construct(public readonly array $event)
    {
    }

    public function handle(
        LineMessagingService $line,
        LineScheduleBot $bot,
        LineScheduleImageService $images,
    ): void {
        if (($this->event['type'] ?? null) !== 'message'
            || ($this->event['message']['type'] ?? null) !== 'text'
            || ! isset($this->event['replyToken'], $this->event['message']['text'])) {
            return;
        }

        $reply = $bot->reply((string) $this->event['message']['text']);

        if ($reply === null) {
            return;
        }

        $replyToken = (string) $this->event['replyToken'];

        if (! $reply->prefersImage()) {
            $line->reply($replyToken, $reply->text);

            return;
        }

        try {
            $imageUrl = $images->create($reply->imageData, $reply->linkUrl);
            $line->replyImageWithLink(
                $replyToken,
                $imageUrl,
                $reply->linkUrl,
            );
        } catch (Throwable $imageException) {
            report($imageException);
            $line->reply($replyToken, $reply->text);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
