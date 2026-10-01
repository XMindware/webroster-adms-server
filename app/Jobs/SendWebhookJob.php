<?php

namespace App\Jobs;

use App\Services\WebhookDeliveryLogger;
use App\Services\WebhookDeliveryResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * POSTs one attendance batch to a device's configured webhook URL.
 *
 * Two things constrain the design:
 *
 * 1. The terminal has already been answered by the time this runs, so nobody is
 *    left to react to a failure. The outcome therefore has to be recorded -
 *    status code and how long the receiver took - or it is lost. That is
 *    WebhookDeliveryLogger's job, and it is the reason this job holds no
 *    knowledge of where the outcome is written.
 *
 * 2. On the sync/after-response path the job runs in the terminate phase of the
 *    request that answered the terminal. An exception escaping that phase
 *    surfaces as an uncaught error in the PHP error log, which is strictly
 *    worse than a missing delivery. So failures throw only when the job is
 *    genuinely being processed off a queue, where a retry means something.
 *
 * The job holds only primitives - no Eloquent model - so it stays valid
 * whatever happens to the device or webhook row afterwards.
 */
class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * Seconds between attempts. Short first - most 5xx blips clear quickly -
     * then longer, then the job is marked failed.
     */
    public array $backoff = [30, 120];

    /**
     * Seconds the worker allows the whole job. Always larger than the HTTP
     * timeout so a slow receiver fails as a logged timeout rather than a killed
     * worker.
     */
    public int $timeout;

    public function __construct(
        public string $url,
        public array $attLog,
        public ?string $sn = null,
        public ?string $secret = null,
        public ?int $webhookId = null,
    ) {
        $this->timeout = (int) config('adms.webhook_timeout', 5) + 10;
    }

    /**
     * The logger is a parameter rather than a hard dependency so a test can
     * hand in its own; the default keeps a bare $job->handle() working.
     */
    public function handle(?WebhookDeliveryLogger $logger = null): void
    {
        $logger ??= app(WebhookDeliveryLogger::class);

        $body = $this->encodeBody();
        $started = microtime(true);

        try {
            $response = $this->send($body, $this->headersFor($body));
        } catch (Throwable $e) {
            $logger->record(
                WebhookDeliveryResult::connectionFailure($e->getMessage(), $this->elapsed($started)),
                $this->logContext()
            );

            $this->retryAfter($e);

            return;
        }

        $result = WebhookDeliveryResult::fromResponse($response, $this->elapsed($started));

        $logger->record($result, $this->logContext());

        // Whether this is worth another attempt was decided by
        // WebhookDeliveryResult, from the same reading that chose the log
        // level - so the two can no longer disagree.
        if ($result->retryable) {
            $this->retryAfter(new RuntimeException((string) $result->error));
        }
    }

    /**
     * Called by the worker once the last attempt has failed. Every attempt
     * already has its own recorded outcome; this one says "and that was the
     * end".
     */
    public function failed(?Throwable $exception = null): void
    {
        app(WebhookDeliveryLogger::class)->recordGivingUp(
            $this->logContext(),
            $this->attempts(),
            $exception?->getMessage()
        );
    }

    /**
     * HMAC-SHA256 over "timestamp.body".
     *
     * The timestamp is inside the signed material so a captured request cannot
     * simply be replayed later: a receiver that rejects old timestamps rejects
     * the replay too, even though the signature itself still verifies.
     */
    public function signatureFor(string $body, int $timestamp): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, (string) $this->secret);
    }

    /**
     * Encoded here rather than handed to Http::post() as an array so the
     * signature covers exactly the bytes the receiver will read.
     */
    private function encodeBody(): string
    {
        return json_encode(['data' => $this->attLog], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $body): array
    {
        $timestamp = now()->getTimestamp();

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Timestamp' => (string) $timestamp,
        ];

        if (!empty($this->secret)) {
            $headers['X-Webhook-Signature'] = $this->signatureFor($body, $timestamp);
        }

        return $headers;
    }

    private function send(string $body, array $headers): Response
    {
        return Http::timeout((int) config('adms.webhook_timeout', 5))
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($this->url);
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(): array
    {
        return [
            'url' => $this->url,
            'sn' => $this->sn,
            'records' => count($this->attLog),
            'webhook_id' => $this->webhookId,
            'attempt' => $this->attempts(),
        ];
    }

    /**
     * Rethrow so the queue retries - but only where there is a queue.
     *
     * On the sync/after-response path the job is handed a SyncJob, whose
     * attempts() is always 1 and which never re-dispatches. Throwing there
     * would not retry anything; it would just produce an uncaught error in the
     * PHP log long after the terminal had gone.
     */
    private function retryAfter(Throwable $e): void
    {
        if ($this->job === null || $this->job->getConnectionName() === 'sync') {
            return;
        }

        throw $e;
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
