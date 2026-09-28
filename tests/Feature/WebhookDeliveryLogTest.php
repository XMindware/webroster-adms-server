<?php

namespace Tests\Feature;

use App\Jobs\SendWebhookJob;
use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The database half of webhook delivery logging.
 *
 * The "webhook" log file is pinned down by WebhookDispatchTest; this covers the
 * webhook_deliveries rows the delivery-history screen reads. Three properties
 * matter, and each is asserted separately:
 *
 *   - every HTTP attempt leaves a row, including the failed ones
 *   - the row says enough to tell "receiver refused us" from "receiver was
 *     never reachable"
 *   - a database that cannot take the row must not take the delivery down with
 *     it, because the write happens in the terminate phase of a request that
 *     has already answered the terminal
 */
class WebhookDeliveryLogTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://receiver.test/hooks/attendance';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests assert on the database sink, so the file sink is silenced
        // rather than redirected: a null channel leaves no rotating file behind
        // for the next test to trip over on Windows.
        config(['logging.channels.webhook' => ['driver' => 'null']]);
        Log::forgetChannel('webhook');
    }

    /**
     * One attendance line, tab separated, exactly as a terminal sends it.
     */
    private function punch(string $employee = '1', string $at = '2026-09-22 08:00:00'): string
    {
        return "{$employee}\t{$at}\t0\t1\t0\t0\t0\r\n";
    }

    private function deviceWithWebhook(?string $url = self::URL, string $sn = 'SN-1'): Device
    {
        if (Oficina::count() === 0) {
            Oficina::create([
                'idempresa' => 1,
                'idoficina' => 2,
                'ubicacion' => 'Cancun',
                'public_url' => 'https://station.test',
                'token' => 'token',
                'iatacode' => 'CUN',
                'timezone' => 'Asia/Jakarta',
            ]);
        }

        $device = Device::create([
            'serial_number' => $sn,
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

    /**
     * A job wired to a given queue connection, so the retry branch can be
     * exercised without standing up a worker.
     *
     * @param  int|null  $attempts  what the queue would report as the attempt number
     */
    private function jobOnConnection(string $connection, ?int $attempts = null): SendWebhookJob
    {
        $job = new SendWebhookJob(self::URL, [['employee_id' => '1']], 'SN-1', 'a-signing-secret');

        $queueJob = $attempts === null
            ? new SyncJob(app(), json_encode([]), $connection, 'default')
            : new class(app(), json_encode([]), $connection, 'default', $attempts) extends SyncJob {
                public function __construct($container, $job, $connectionName, $queue, private int $reported)
                {
                    parent::__construct($container, $job, $connectionName, $queue);
                }

                public function attempts()
                {
                    return $this->reported;
                }
            };

        $job->setJob($queueJob);

        return $job;
    }

    public function test_a_successful_delivery_is_recorded(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $device = $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        $delivery = WebhookDelivery::sole();

        $this->assertTrue($delivery->successful);
        $this->assertSame(200, $delivery->status);
        $this->assertSame(1, $delivery->records);
        $this->assertSame('SN-1', $delivery->device_sn);
        $this->assertSame(self::URL, $delivery->url);
        $this->assertSame($device->webhook->id, $delivery->webhook_id);
        $this->assertNull($delivery->error);
        $this->assertIsInt($delivery->duration_ms);
    }

    /**
     * A receiver refusing the payload is the case an operator most needs to
     * see, because it never shows up anywhere else: the terminal was answered
     * before the POST happened.
     */
    public function test_a_client_error_is_recorded_with_its_status(): void
    {
        Http::fake([self::URL => Http::response('unprocessable', 422)]);
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        $delivery = WebhookDelivery::sole();

        $this->assertFalse($delivery->successful);
        $this->assertSame(422, $delivery->status);
        $this->assertStringContainsString('422', $delivery->error);
    }

    public function test_a_server_error_is_recorded_before_the_job_is_retried(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        try {
            $this->jobOnConnection('database')->handle();
            $this->fail('a 5xx off a real queue must be rethrown so the worker retries');
        } catch (RuntimeException $e) {
            // The retry the worker will make, not a failure of the logging.
        }

        $delivery = WebhookDelivery::sole();

        $this->assertFalse($delivery->successful);
        $this->assertSame(500, $delivery->status);
        $this->assertStringContainsString('500', $delivery->error);
    }

    /**
     * No status at all is the distinguishing mark of "never reached": a
     * timeout, a refused connection or a DNS failure, as opposed to a receiver
     * that answered badly.
     */
    public function test_a_connection_failure_is_recorded_without_a_status(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        $this->deviceWithWebhook();

        $this->postAttlog($this->punch())->assertOk();

        $delivery = WebhookDelivery::sole();

        $this->assertFalse($delivery->successful);
        $this->assertNull($delivery->status);
        $this->assertStringContainsString('Connection timed out', $delivery->error);
    }

    public function test_a_device_without_a_webhook_records_nothing(): void
    {
        Http::fake();
        $this->deviceWithWebhook(null);

        $this->postAttlog($this->punch())->assertOk();

        $this->assertSame(0, WebhookDelivery::count());
    }

    /**
     * One row per HTTP attempt, not one per batch: the point of the table is to
     * show that a receiver was hit three times and refused three times.
     */
    public function test_every_attempt_gets_its_own_row(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $job = $this->jobOnConnection('database');

        foreach (range(1, 3) as $ignored) {
            try {
                $job->handle();
            } catch (RuntimeException $e) {
                // the worker's retry, not a failure of the logging
            }
        }

        $this->assertSame(3, WebhookDelivery::count());
        $this->assertSame(3, WebhookDelivery::where('status', 500)->count());
        $this->assertSame(0, WebhookDelivery::where('successful', true)->count());
    }

    /**
     * The attempt number comes from the queue job, so a row says which of the
     * tries it was rather than only that there were some.
     */
    public function test_the_row_records_which_attempt_it_was(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        try {
            $this->jobOnConnection('database', 2)->handle();
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(2, WebhookDelivery::sole()->attempt);
    }

    /**
     * The write happens in the terminate phase of a request that has already
     * answered the terminal. An uncaught error there would land in the PHP
     * error log long after the terminal had gone and change nothing about the
     * delivery - so a database that cannot take the row must not take the
     * delivery down with it.
     */
    public function test_a_failing_database_write_does_not_break_the_delivery(): void
    {
        Http::fake([self::URL => Http::response('', 200)]);
        $this->deviceWithWebhook();

        Schema::rename('webhook_deliveries', 'webhook_deliveries_missing');

        $this->postAttlog($this->punch())->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_history_screen_lists_what_was_sent(): void
    {
        $webhook = $this->deviceWithWebhook()->webhook;

        // Seeded rather than posted: Application::terminate() does not clear
        // its terminating callbacks, so a second request inside one test would
        // re-run the first one's after-response job. That is a test artefact,
        // and the row this screen renders is already covered above.
        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'device_sn' => 'SN-1',
            'url' => self::URL,
            'records' => 7,
            'attempt' => 1,
            'status' => 200,
            'duration_ms' => 42,
            'successful' => true,
        ]);

        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'device_sn' => 'SN-1',
            'url' => self::URL,
            'records' => 3,
            'attempt' => 3,
            'status' => 500,
            'duration_ms' => 120,
            'successful' => false,
            'error' => 'webhook receiver answered 500',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('webhooks.deliveries', ['id' => $webhook->id]) . '?lang=en');

        $response->assertOk();
        $response->assertSee('SN-1', false);
        $response->assertSee(self::URL, false);
        $response->assertSee('Delivered', false);
        $response->assertSee('Failed', false);
        $response->assertSee('42 ms', false);
        $response->assertSee('webhook receiver answered 500', false);
    }

    /**
     * A second webhook's deliveries must not bleed into this one's history -
     * the whole point of the screen is "what did this receiver get".
     */
    public function test_the_history_screen_only_shows_its_own_webhook(): void
    {
        $webhook = $this->deviceWithWebhook()->webhook;

        $other = $this->deviceWithWebhook(self::URL, 'SN-2')->webhook;

        WebhookDelivery::create([
            'webhook_id' => $other->id,
            'device_sn' => 'SN-2',
            'url' => 'https://other.test/hooks',
            'records' => 1,
            'attempt' => 1,
            'status' => 200,
            'duration_ms' => 5,
            'successful' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('webhooks.deliveries', ['id' => $webhook->id]) . '?lang=en');

        $response->assertOk();
        $response->assertSee('No deliveries recorded yet.', false);
        $response->assertDontSee('https://other.test/hooks', false);
    }

    public function test_the_history_screen_follows_the_locale(): void
    {
        $webhook = $this->deviceWithWebhook()->webhook;
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('webhooks.deliveries', ['id' => $webhook->id]) . '?lang=en')
            ->assertOk()
            ->assertSee('Delivery history', false);

        $this->actingAs($user)
            ->get(route('webhooks.deliveries', ['id' => $webhook->id]) . '?lang=id')
            ->assertOk()
            ->assertSee('Riwayat pengiriman', false);
    }

    public function test_the_history_screen_is_not_reachable_without_a_session(): void
    {
        $webhook = $this->deviceWithWebhook()->webhook;

        $this->get(route('webhooks.deliveries', ['id' => $webhook->id]))
            ->assertRedirect(route('login'));
    }

    public function test_a_webhook_that_no_longer_exists_redirects_to_the_index(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('webhooks.deliveries', ['id' => 999999]))
            ->assertRedirect(route('webhooks.index'));
    }
}
