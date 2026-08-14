<?php

namespace App\Jobs;

use App\Services\LineMessagingService;
use App\Services\LineScheduleBot;
use App\Services\LineScheduleImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    public function __construct(
        public array $event,
        public ?int $webhookReceivedAtMs = null,
    ) {
        $this->webhookReceivedAtMs ??= $this->nowMs();
    }

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
        $startedAtMs = $this->nowMs();
        $queueLatencyMs = max(0, $startedAtMs - (int) $this->webhookReceivedAtMs);
        $webhookLog = $this->webhookLogger();
        $message = (string) ($this->event['message']['text'] ?? '');
        $loggableMessage = preg_replace('/^\p{Cf}+/u', '', trim($message)) ?? trim($message);
        $command = preg_match('/^[!！﹗]/u', $loggableMessage) === 1
            ? mb_substr($loggableMessage, 0, 120)
            : '[non-command]';
        $messageId = $this->event['message']['id'] ?? null;

        $this->writeLog($webhookLog, 'info', 'LINE webhook received.', [
            'webhook_event_id' => $this->event['webhookEventId'] ?? null,
            'message_id' => $messageId,
            'command' => $command,
            'queue_latency_ms' => $queueLatencyMs,
            'event_age_ms' => $this->eventAgeMs($startedAtMs),
            'is_redelivery' => (bool) ($this->event['deliveryContext']['isRedelivery'] ?? false),
        ]);

        try {
            $reply = $bot->reply($message);

            if ($reply === null) {
                $this->writeLog($webhookLog, 'info', 'LINE webhook message ignored.', [
                    'message_id' => $messageId,
                    'command' => $command,
                    'message_prefix_hex' => bin2hex(mb_substr($message, 0, 8)),
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

            $deliveryMethod = 'reply';
            $deliveryResult = null;
            $replyTokenAgeMs = $this->replyTokenAgeMs();
            $target = $this->pushTarget();

            if ($this->replyTokenWindowExceeded($replyTokenAgeMs) && $target !== null) {
                $this->writeLog($webhookLog, 'warning', 'LINE reply token window exceeded; sending push.', [
                    'message_id' => $messageId,
                    'reply_token_age_ms' => $replyTokenAgeMs,
                    'has_push_target' => true,
                ]);

                $deliveryMethod = 'push_expired_reply_token';
                $deliveryResult = $imageUrl !== null
                    ? $line->pushImageWithLink($target, $imageUrl, $reply->linkUrl)
                    : $line->push($target, $reply->text);
            } else {
                try {
                    if ($imageUrl !== null) {
                        $deliveryResult = $line->replyImageWithLink($replyToken, $imageUrl, $reply->linkUrl);
                    } else {
                        $deliveryResult = $line->reply($replyToken, $reply->text);
                    }
                } catch (Throwable $replyException) {
                    // Queue latency or slow upstream APIs can make LINE's short-lived
                    // reply token expire. A push message does not depend on that token.
                    $this->writeLog($webhookLog, 'warning', 'LINE reply failed; falling back to push.', [
                        'message_id' => $messageId,
                        'type' => $replyException::class,
                        'status' => $this->exceptionStatus($replyException),
                        'line_error' => $this->lineError($replyException),
                        'reply_token_age_ms' => $replyTokenAgeMs,
                        'has_push_target' => $target !== null,
                    ]);

                    if ($target === null) {
                        throw $replyException;
                    }

                    // Use text for the recovery path. If LINE rejected the image URL,
                    // retrying the same image as a push would fail for the same reason.
                    $deliveryMethod = 'push_after_reply_failure';
                    $deliveryResult = $line->push($target, $reply->text);
                }
            }

            $this->writeLog($webhookLog, 'info', 'LINE webhook replied.', [
                'message_id' => $messageId,
                'command' => $command,
                'delivery_method' => $deliveryMethod,
                'line_status' => $deliveryResult['status'] ?? null,
                'line_request_id' => $deliveryResult['request_id'] ?? null,
                'queue_latency_ms' => $queueLatencyMs,
                'reply_token_age_ms' => $this->replyTokenAgeMs(),
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

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function replyTokenAgeMs(): int
    {
        return max(0, $this->nowMs() - (int) $this->webhookReceivedAtMs);
    }

    private function replyTokenWindowExceeded(int $ageMs): bool
    {
        $safeWindowSeconds = max(1, (int) config('services.line.reply_token_safe_window_seconds', 45));

        return $ageMs >= $safeWindowSeconds * 1000;
    }

    private function eventAgeMs(int $nowMs): ?int
    {
        $timestamp = $this->event['timestamp'] ?? null;

        return is_numeric($timestamp) ? max(0, $nowMs - (int) $timestamp) : null;
    }

    private function exceptionStatus(Throwable $exception): ?int
    {
        return $exception instanceof RequestException
            ? $exception->response->status()
            : null;
    }

    private function lineError(Throwable $exception): ?string
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $message = $exception->response->json('message');

        return is_string($message) ? mb_substr($message, 0, 500) : null;
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

    private function webhookLogger(): LoggerInterface
    {
        try {
            return Log::channel('webhook');
        } catch (Throwable $exception) {
            error_log(sprintf('Unable to create webhook logger: %s', $exception->getMessage()));

            return new NullLogger;
        }
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
