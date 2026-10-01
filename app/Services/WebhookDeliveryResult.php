<?php

namespace App\Services;

use Illuminate\Http\Client\Response;

/**
 * The outcome of a single webhook delivery attempt.
 *
 * This exists because the same Response used to be read twice, for two
 * different decisions: once to pick a log level, and once to decide whether a
 * retry was worth it. Those two readings lived in different branches of
 * SendWebhookJob::handle() and could drift apart - a 422 logged as a warning in
 * one branch while the other branch retried it anyway.
 *
 * Both answers are derived here from one place, so "recorded as not retryable"
 * and "not retried" cannot disagree.
 *
 *   delivered   2xx  info     not retryable
 *   rejected    4xx  warning  not retryable  (same payload would be refused again)
 *   rejected    5xx  error    retryable      (may be transient)
 *   unreachable -    error    retryable      (transport error, no status)
 */
final readonly class WebhookDeliveryResult
{
    private function __construct(
        public bool $successful,
        public bool $retryable,
        public ?int $status,
        public int $durationMs,
        public ?string $error,
    ) {
    }

    public static function fromResponse(Response $response, int $durationMs): self
    {
        if ($response->successful()) {
            return new self(true, false, $response->status(), $durationMs, null);
        }

        // A 4xx is the receiver refusing this payload and it will refuse the
        // same payload again, so retrying only duplicates the delivery. A 5xx
        // may be transient.
        $refused = $response->clientError();

        return new self(
            false,
            !$refused,
            $response->status(),
            $durationMs,
            'webhook receiver answered ' . $response->status(),
        );
    }

    /**
     * The receiver was never reached, so there is no status code - only the
     * transport error.
     */
    public static function connectionFailure(string $error, int $durationMs): self
    {
        return new self(false, true, null, $durationMs, $error);
    }

    public function logLevel(): string
    {
        if ($this->successful) {
            return 'info';
        }

        // A 4xx is a configuration problem on one side or the other and will
        // not fix itself; a 5xx or a transport error may.
        return $this->retryable ? 'error' : 'warning';
    }

    public function logMessage(): string
    {
        if ($this->successful) {
            return 'webhook delivered';
        }

        return $this->status === null ? 'webhook request failed' : 'webhook rejected';
    }
}
