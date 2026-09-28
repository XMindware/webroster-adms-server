<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every webhook delivery attempt in the two places it needs to be
 * seen.
 *
 * The "webhook" log channel is what an operator tails live; the
 * webhook_deliveries table is what the delivery-history screen reads. Neither
 * audience can be served by the other - a rotated log cannot be filtered by
 * device, and a database query cannot be tailed.
 *
 * Both writes are individually guarded and neither ever throws. This runs in
 * the terminate phase of the request that already answered the terminal (see
 * SendWebhookJob), where an uncaught exception is strictly worse than a missing
 * log line: it would land in the PHP error log long after the terminal had gone
 * and change nothing about the delivery.
 */
class WebhookDeliveryLogger
{
    /**
     * @param array{url?: string, sn?: ?string, records?: int, webhook_id?: ?int, attempt?: int} $context
     */
    public function record(WebhookDeliveryResult $result, array $context): void
    {
        $context = array_merge(['records' => 0, 'attempt' => 1], $context);

        $logContext = array_merge($context, [
            'status' => $result->status,
            'duration_ms' => $result->durationMs,
        ]);

        // The transport error is the only thing that explains an unreachable
        // receiver, and it is the one field the file log has always carried.
        if ($result->error !== null) {
            $logContext['error'] = $result->error;
        }

        $this->toLogFile($result->logLevel(), $result->logMessage(), $logContext);

        $this->toDatabase($result, $context);
    }

    /**
     * The end of a retry sequence, written by SendWebhookJob::failed().
     *
     * File only, deliberately: this is not an HTTP attempt, so there is no
     * delivery to add to the table - the per-attempt rows already say what
     * happened, and a "gave up" row would only restate the last of them. The
     * line is kept because it marks where the sequence ended in a tail of
     * webhook.log.
     *
     * @param array<string, mixed> $context
     */
    public function recordGivingUp(array $context, int $attempts, ?string $error): void
    {
        $this->toLogFile('error', 'webhook delivery failed', array_merge($context, [
            'attempts' => $attempts,
            'error' => $error,
        ]));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function toLogFile(string $level, string $message, array $context): void
    {
        try {
            Log::channel('webhook')->log($level, $message, $context);
        } catch (Throwable) {
            // A cached config that predates the "webhook" channel must not turn
            // a delivery failure into an uncaught error.
            Log::log($level, '[webhook] ' . $message, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function toDatabase(WebhookDeliveryResult $result, array $context): void
    {
        try {
            WebhookDelivery::create([
                'webhook_id' => $context['webhook_id'] ?? null,
                'device_sn' => $context['sn'] ?? null,
                'url' => $context['url'] ?? '',
                'records' => $context['records'] ?? 0,
                'attempt' => $context['attempt'] ?? 1,
                'status' => $result->status,
                'duration_ms' => $result->durationMs,
                'successful' => $result->successful,
                'error' => $result->error,
            ]);
        } catch (Throwable $e) {
            // The migration may not have run yet on this deploy, or the
            // connection may have gone. Neither is a reason to lose the
            // delivery itself, so the failure is reported and swallowed.
            $this->reportDatabaseFailure($e);
        }
    }

    private function reportDatabaseFailure(Throwable $e): void
    {
        try {
            Log::warning('webhook delivery could not be recorded in the database', [
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // The default channel is unavailable too. Nothing left to report
            // to, and still nothing worth throwing for.
        }
    }
}
