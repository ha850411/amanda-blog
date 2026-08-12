<?php

namespace App\Jobs;

use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class ProcessLineWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [2, 10];

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(public array $event) {}

    public function uniqueId(): string
    {
        return (string) (
            $this->event['webhookEventId']
            ?? $this->event['message']['id']
            ?? sha1(json_encode($this->event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
        );
    }

    public function handle(
        LineMessagingService $line,
        LineScheduleBot $bot,
        LineScheduleImageService $images,
    ): void {
        $startedAt = hrtime(true);
        $webhookLog = Log::channel('webhook');
        $message = (string) ($this->event['message']['text'] ?? '');
        $command = str_starts_with(trim($message), '!')
            ? mb_substr(trim($message), 0, 120)
            : '[non-command]';
        $messageId = $this->event['message']['id'] ?? null;

        $this->writeLog($webhookLog, 'info', 'LINE webhook received.', [
            'message_id' => $messageId,
            'command' => $command,
        ]);

        try {
            $reply = $bot->reply($message);

            if ($reply === null) {
                $this->writeLog($webhookLog, 'info', 'LINE webhook message ignored.', [
                    'message_id' => $messageId,
                    'command' => $command,
                    'duration_ms' => $this->durationMs($startedAt),
                ]);

                return;
            }

            $replyToken = (string) ($this->event['replyToken'] ?? '');

            $imageUrl = null;

            if ($reply->prefersImage()) {
                try {
                    $imageUrl = $images->create($reply->imageData, $reply->linkUrl);
                } catch (Throwable $imageException) {
                    // Image storage is optional. The text response still contains
                    // the filtered overview URL and is the supported fallback.
                    $this->writeLog($webhookLog, 'warning', 'LINE schedule image unavailable; falling back to text.', [
                        'message_id' => $messageId,
                        'type' => $imageException::class,
                        'error' => $imageException->getMessage(),
                    ]);
                }
            }

            try {
                if ($imageUrl !== null) {
                    $line->replyImageWithLink($replyToken, $imageUrl, $reply->linkUrl);
                } else {
                    $line->reply($replyToken, $reply->text);
                }
            } catch (Throwable $replyException) {
                // Queue latency or slow upstream APIs can make LINE's short-lived
                // reply token expire. A push message does not depend on that token.
                $target = $this->pushTarget();

                $this->writeLog($webhookLog, 'warning', 'LINE reply failed; falling back to push.', [
                    'message_id' => $messageId,
                    'type' => $replyException::class,
                    'has_push_target' => $target !== null,
                ]);

                if ($target === null) {
                    throw $replyException;
                }

                // Use text for the recovery path. If LINE rejected the image URL,
                // retrying the same image as a push would fail for the same reason.
                $line->push($target, $reply->text);
            }

            $this->writeLog($webhookLog, 'info', 'LINE webhook replied.', [
                'message_id' => $messageId,
                'command' => $command,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
        } catch (Throwable $exception) {
            $this->writeLog($webhookLog, 'error', 'LINE webhook failed.', [
                'message_id' => $messageId,
                'type' => $exception::class,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            try {
                Log::error('Failed to handle LINE webhook event.', [
                    'exception' => $exception,
                    'message_id' => $messageId,
                ]);
            } catch (Throwable $loggingException) {
                error_log(sprintf('Unable to write application log: %s', $loggingException->getMessage()));
            }

            // Let the queue retry transient delivery failures and record the job
            // in failed_jobs if every attempt is exhausted.
            throw $exception;
        }
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function pushTarget(): ?string
    {
        $source = $this->event['source'] ?? null;

        if (! is_array($source)) {
            return null;
        }

        $key = match ($source['type'] ?? null) {
            'group' => 'groupId',
            'room' => 'roomId',
            'user' => 'userId',
            default => null,
        };

        if ($key === null || ! is_string($source[$key] ?? null) || $source[$key] === '') {
            return null;
        }

        return $source[$key];
    }

    /**
     * Logging is diagnostic and must never prevent the LINE event from being handled.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeLog(
        LoggerInterface $logger,
        string $level,
        string $message,
        array $context = [],
    ): void {
        try {
            $logger->log($level, $message, $context);
        } catch (Throwable $exception) {
            error_log(sprintf('Unable to write webhook log: %s', $exception->getMessage()));
        }
    }
}
