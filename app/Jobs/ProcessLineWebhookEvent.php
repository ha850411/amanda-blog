<?php

namespace App\Jobs;

use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessLineWebhookEvent implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 50;

    public int $uniqueFor = 300;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $event */
    public function __construct(
        public readonly array $event,
        public readonly bool $processingAcknowledged = false,
    ) {
    }

    public function uniqueId(): string
    {
        if (is_string($this->event['webhookEventId'] ?? null)
            && $this->event['webhookEventId'] !== '') {
            return $this->event['webhookEventId'];
        }

        return hash('sha256', json_encode([
            $this->event['message']['id'] ?? null,
            $this->event['replyToken'] ?? null,
            $this->event['timestamp'] ?? null,
        ], JSON_THROW_ON_ERROR));
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

        $target = $this->processingAcknowledged ? $this->targetId() : null;

        if ($target !== null) {
            if (! $reply->prefersImage()) {
                $line->push($target, $reply->text);

                return;
            }

            try {
                $imageUrl = $images->create($reply->imageData, $reply->linkUrl);
                $line->pushImageWithLink(
                    $target,
                    $imageUrl,
                    $reply->linkUrl,
                );
            } catch (Throwable $imageException) {
                report($imageException);
                $line->push($target, $reply->text);
            }

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

    private function targetId(): ?string
    {
        $source = is_array($this->event['source'] ?? null) ? $this->event['source'] : [];
        $target = match ($source['type'] ?? null) {
            'group' => $source['groupId'] ?? null,
            'room' => $source['roomId'] ?? null,
            'user' => $source['userId'] ?? null,
            default => null,
        };

        return is_string($target) && $target !== '' ? $target : null;
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
