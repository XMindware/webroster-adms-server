<?php

namespace Tests\Feature;

use App\Jobs\SendWebhookJob;
use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Webhook delivery: what gets forwarded, and what a failure leaves behind.
 *
 * The POST deliberately happens after the terminal has been answered (see
 * iclockController::dispatchWebhook), so a receiver's status code can never
 * reach the terminal. The "webhook" log channel is therefore the only place a
 * broken receiver becomes visible, and that is what most of these tests pin
 * down.
 */
class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://receiver.test/hooks/attendance';

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The real channel rotates a file under storage/logs. Point it at a
        // throwaway file so the assertions can read back what was written.
        //
        // The path is unique per test rather than a fixed name. A fixed name
        // breaks on Windows: the previous test's Monolog handler still holds
        // the file open when setUp deletes it, so the delete succeeds but the
        // path stays in a pending-delete state, and opening it again fails with
        // "Permission denied" until that handle closes. The write is then
        // swallowed by SendWebhookJob's fallback and the test sees an empty
        // file - which is exactly how these six assertions went red while the
        // same code had been green an hour earlier, with nothing changed but
        // garbage-collection timing.
        $this->logPath = storage_path('logs/webhook-test-' . bin2hex(random_bytes(8)) . '.log');

        config(['logging.channels.webhook' => [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'debug',
        ]]);

        Log::forgetChannel('webhook');
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    /**
     * One attendance line, tab separated, exactly as a terminal sends it.
     */
    private function punch(string $employee = '1', string $at = '2026-09-22 08:00:00'): string
    {
        return "{$employee}\t{$at}\t0\t1\t0\t0\t0\r\n";
    }

    private function deviceWithWebhook(?string $url = self::URL): Device
    {
        Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
            'timezone' => 'Asia/Jakarta',
        ]);

        $device = Device::create([
            'serial_number' => 'SN-1',
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
            'name' => 'Test device',
        ]);

        if ($url !== null) {
            Webhook::create(['device_id' => $device->id, 'url' => $url]);
        }

        return $device;
    }

    /**
     * The body arrives as a plain body rather than form fields, so it has to go
     * through call() to reach $request->getContent() intact.
     */
    private function postAttlog(string $body): TestResponse
    {
        return $this->call(
            'POST',
            '/iclock/cdata?SN=SN-1&table=ATTLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $body
        );
    }

    private function webhookLog(): string
    {
        return file_exists($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function test_attendance_batch_is_posted_to_the_devices_webhook(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === self::URL
                && count($data['data'] ?? []) === 1
                && $data['data'][0]['employee_id'] === '1'
                && $data['data'][0]['sn'] === 'SN-1';
        });
    }

    public function test_a_device_without_a_webhook_posts_nothing(): void
    {
        Http::fake();
        $this->deviceWithWebhook(null);

        $this->postAttlog($this->punch())->assertOk();

        Http::assertNothingSent();
    }

    /**
     * A terminal that cannot advance its watermark re-sends the same batch. The
     * receiver must not see rows it has already been given a second time.
     *
     * The earlier delivery is seeded straight into the table rather than
     * replayed with a second request: Application::terminate() does not clear
     * its terminating callbacks, so within one test a second request would re-run
     * the first request's after-response job as well. Each request gets a fresh
     * application in production, so that is a test artefact, not a behaviour.
     */
    public function test_already_stored_rows_are_not_forwarded(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $this->deviceWithWebhook();

        DB::table('attendances')->insert([
            'sn' => 'SN-1',
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => '1',
            'timestamp' => '2026-09-22 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postAttlog($this->punch())->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_successful_delivery_is_logged_with_status_and_duration(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch());

        $log = $this->webhookLog();

        $this->assertStringContainsString('webhook delivered', $log);
        $this->assertStringContainsString('"status":200', $log);
        $this->assertStringContainsString('"duration_ms":', $log);
        $this->assertStringContainsString(self::URL, $log);
        $this->assertStringContainsString('SN-1', $log);
    }

    public function test_a_server_error_is_logged_with_its_status(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch());

        $log = $this->webhookLog();

        $this->assertStringContainsString('webhook rejected', $log);
        $this->assertStringContainsString('"status":500', $log);
        $this->assertStringContainsString('"duration_ms":', $log);
        $this->assertStringContainsString('ERROR', $log);
    }

    /**
     * A 4xx is the receiver refusing the payload and will not fix itself, so it
     * is recorded at a lower severity than a 5xx.
     */
    public function test_a_client_error_is_logged_as_a_warning(): void
    {
        Http::fake([self::URL => Http::response('unprocessable', 422)]);
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch());

        $log = $this->webhookLog();

        $this->assertStringContainsString('webhook rejected', $log);
        $this->assertStringContainsString('"status":422', $log);
        $this->assertStringContainsString('WARNING', $log);
    }

    /**
     * The receiver being unreachable used to be the one failure that could not
     * be seen at all: Http::post only throws on transport errors, and the old
     * code swallowed them into a log nobody watched.
     */
    public function test_a_connection_failure_is_logged(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        $log = $this->webhookLog();

        $this->assertStringContainsString('webhook request failed', $log);
        $this->assertStringContainsString('Connection timed out', $log);
        $this->assertStringContainsString('"duration_ms":', $log);
    }

    /**
     * The reply count is the terminal's upload watermark. A broken receiver
     * must not change it, otherwise the terminal concludes the batch failed and
     * re-sends it forever.
     */
    public function test_a_failing_receiver_does_not_change_the_terminal_reply(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->deviceWithWebhook();

        $response = $this->postAttlog($this->punch());

        $this->assertSame('OK: 1', trim($response->getContent()));
    }

    /**
     * Only the attendance path forwards. User records, operation logs and
     * fingerprint templates go through receiveBiometricRecords(), which never
     * calls the webhook - so a receiver must not expect them.
     */
    public function test_biometric_uploads_are_not_forwarded(): void
    {
        Http::fake();
        $this->deviceWithWebhook();

        $this->call(
            'POST',
            '/iclock/cdata?SN=SN-1&table=OPERLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "OPLOG\t1\t0\t0\t0\t0\t0\r\n"
        );

        Http::assertNothingSent();
    }

    /**
     * With the sync connection there is no worker to hand the job to, so it has
     * to run in the terminate phase - after the terminal already has its reply.
     *
     * A terminating callback registered here runs before the one the job
     * registers while handling the request, so if the POST observes the flag it
     * fired during terminate and not inline inside the controller.
     */
    public function test_the_post_runs_in_the_terminate_phase_not_during_the_request(): void
    {
        $terminating = false;

        $this->app->terminating(function () use (&$terminating) {
            $terminating = true;
        });

        $this->deviceWithWebhook();

        $observed = null;

        Http::fake(function () use (&$terminating, &$observed) {
            $observed = $terminating;

            return Http::response('', 200);
        });

        $this->postAttlog($this->punch())->assertOk();

        $this->assertTrue(
            $observed,
            'the POST must run after the terminal has been answered'
        );
    }

    /**
     * Point QUEUE_CONNECTION at a real driver and run a worker, and the same
     * job is pushed to the queue instead of being run in the terminate phase.
     */
    public function test_the_job_is_queued_when_a_real_queue_connection_is_configured(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);

        $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) {
            return $job->url === self::URL
                && $job->sn === 'SN-1'
                && count($job->attLog) === 1;
        });
    }

    /**
     * A missing "webhook" channel - a config cache built before it existed, for
     * instance - must degrade to the default channel rather than turn a
     * delivery failure into an uncaught error.
     */
    public function test_a_missing_log_channel_does_not_break_delivery(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->deviceWithWebhook();

        config(['logging.channels.webhook' => null]);
        Log::forgetChannel('webhook');

        $this->postAttlog($this->punch())->assertOk();

        Http::assertSentCount(1);
    }

    private function actingUser(): self
    {
        return $this->actingAs(User::factory()->create());
    }

    /**
     * A job wired to a given queue connection, so the retry branch can be
     * exercised without standing up a worker.
     */
    private function jobOnConnection(string $connection): SendWebhookJob
    {
        $job = new SendWebhookJob(self::URL, [['employee_id' => '1']], 'SN-1', 'a-signing-secret');

        $job->setJob(new SyncJob(app(), json_encode([]), $connection, 'default'));

        return $job;
    }

    public function test_a_new_webhook_gets_a_signing_secret(): void
    {
        $device = $this->deviceWithWebhook(null);

        $webhook = Webhook::create(['device_id' => $device->id, 'url' => self::URL]);

        $this->assertNotEmpty($webhook->secret);
    }

    public function test_the_delivery_is_signed_with_the_webhook_secret(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Webhook-Timestamp')[0] ?? null;
            $signature = $request->header('X-Webhook-Signature')[0] ?? null;

            if (!$timestamp || !$signature) {
                return false;
            }

            $expected = 'sha256=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $request->body(),
                (string) Webhook::first()->secret
            );

            return hash_equals($expected, $signature);
        });
    }

    /**
     * A row created before the secret column existed has none. It is still
     * delivered - just unsigned, which is a choice the receiver has to be able
     * to see rather than something that silently drops the delivery.
     */
    public function test_a_webhook_without_a_secret_is_delivered_unsigned(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $this->deviceWithWebhook();

        DB::table('webhooks')->update(['secret' => null]);

        $this->postAttlog($this->punch())->assertOk();

        Http::assertSent(function ($request) {
            return !$request->hasHeader('X-Webhook-Signature')
                && $request->hasHeader('X-Webhook-Timestamp');
        });
    }

    public function test_regenerating_the_secret_rotates_it(): void
    {
        $this->deviceWithWebhook();
        $webhook = Webhook::first();
        $before = $webhook->secret;

        $response = $this->actingUser()->post(route('webhooks.secret', ['id' => $webhook->id]));

        $response->assertRedirect(route('webhooks.index'));
        $response->assertSessionHas('success');
        $this->assertNotSame($before, $webhook->fresh()->secret);
    }

    /**
     * Off a real queue a 5xx is worth another attempt, so the job fails and the
     * worker releases it.
     */
    public function test_a_server_error_is_retried_off_a_real_queue(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->expectException(RuntimeException::class);

        $this->jobOnConnection('database')->handle();
    }

    /**
     * On the sync/after-response path there is no worker and no attempt
     * counter. Rethrowing would not retry anything - it would only surface as
     * an uncaught error long after the terminal went away.
     */
    public function test_a_server_error_is_not_rethrown_inline(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->jobOnConnection('sync')->handle();

        $this->assertStringContainsString('webhook rejected', $this->webhookLog());
    }

    /**
     * A 4xx is the receiver refusing this payload, and it will refuse the same
     * payload again - retrying only duplicates the delivery.
     */
    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake([self::URL => Http::response('nope', 422)]);

        $this->jobOnConnection('database')->handle();

        $this->assertStringContainsString('webhook rejected', $this->webhookLog());
    }

    /**
     * A transport error is the case that most deserves a retry.
     */
    public function test_a_transport_error_is_retried_off_a_real_queue(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(ConnectionException::class);

        $this->jobOnConnection('database')->handle();
    }
}
