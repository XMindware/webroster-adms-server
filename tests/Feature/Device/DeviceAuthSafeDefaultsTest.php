<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * S2, Phase 1 decision (2026-11-17): device auth ships log-only. Anything
 * that is not exactly "enforce" must mean log, the device rate limiter is
 * off by default, and a failing device lookup must not break /iclock in
 * log mode.
 */
class DeviceAuthSafeDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = 'ADMS_DEVICE_AUTH';

    private const SN = 'FAKE-SN-OK';

    private const DB_ERROR_MARKER = 'FAKE-DB-FAILURE-MARKER';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');

        Oficina::create([
            'idempresa' => 1, 'idoficina' => 2, 'ubicacion' => 'Fake Station',
            'public_url' => 'https://station.test', 'token' => 'fake-token',
            'iatacode' => 'XXX', 'timezone' => 'America/Cancun',
        ]);
        Device::create(['serial_number' => self::SN, 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);
    }

    protected function tearDown(): void
    {
        $this->setEnv(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function nonEnforceValues(): array
    {
        return [
            'unset' => [null],
            'upper case' => ['LOG'],
            'leading space' => [' log'],
            'trailing space' => ['log '],
            'unknown value' => ['off'],
            'typo' => ['enforced'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider nonEnforceValues
     */
    public function test_device_auth_values_other_than_enforce_resolve_to_log(?string $value): void
    {
        $this->setEnv($value);

        $this->assertSame('log', $this->freshConfig()['device_auth']);
    }

    public function test_enforce_still_resolves_to_enforce(): void
    {
        $this->setEnv('enforce');

        $this->assertSame('enforce', $this->freshConfig()['device_auth']);
    }

    public function test_device_rate_limiter_is_off_by_default(): void
    {
        $limiter = RateLimiter::limiter('device');
        $request = Request::create('/iclock/rtdata?SN='.self::SN);
        $limit = $limiter === null ? null : $limiter($request);

        $this->assertTrue(
            $limit === null || $limit instanceof Unlimited,
            'the device rate limiter must not limit anything by default'
        );
    }

    public function test_device_endpoints_send_no_rate_limit_headers_and_never_429_by_default(): void
    {
        // One more than the per-serial limit 6ae5089 ships with (600/min).
        for ($i = 1; $i <= 601; $i++) {
            $response = $this->get('/iclock/rtdata?SN='.self::SN.'&type=time');

            $this->assertSame(200, $response->getStatusCode(), "request $i was throttled");
            $this->assertFalse($response->headers->has('X-RateLimit-Limit'), "request $i carries X-RateLimit-Limit");
            $this->assertFalse($response->headers->has('X-RateLimit-Remaining'), "request $i carries X-RateLimit-Remaining");
        }
    }

    public function test_log_mode_serves_the_staging_response_when_the_device_lookup_fails(): void
    {
        $this->failDeviceLookups();

        $this->get('/iclock/getrequest?SN='.self::SN)
            ->assertOk()
            ->assertContent('OK');

        $this->get('/iclock/cdata?SN='.self::SN.'&options=all')
            ->assertOk()
            ->assertSee('GET OPTION FROM: '.self::SN, false);

        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=ATTLOG&Stamp=9999', [], [], [],
            ['CONTENT_TYPE' => 'text/plain'], "1001\t2026-11-15 09:58:00\t0\t1\t0\t0")
            ->assertOk()
            ->assertContent('OK: 1');

        $this->assertSame(1, DB::table('attendances')->where('sn', self::SN)->count());
    }

    public function test_enforce_mode_fails_closed_when_the_device_lookup_fails(): void
    {
        config(['adms.device_auth' => 'enforce']);
        $this->failDeviceLookups();

        $this->get('/iclock/cdata?SN='.self::SN.'&options=all')
            ->assertForbidden()
            ->assertContent('ERROR: Device not registered');

        $this->call('POST', '/iclock/cdata?SN='.self::SN.'&table=ATTLOG&Stamp=9999', [], [], [],
            ['CONTENT_TYPE' => 'text/plain'], "1001\t2026-11-15 09:58:00\t0\t1\t0\t0")
            ->assertForbidden()
            ->assertContent('ERROR: Device not registered');

        $this->assertSame(0, DB::table('attendances')->count());
        $this->assertSame(0, DB::table('device_log')->count());
        $this->assertSame(0, DB::table('finger_log')->count());
        $this->assertSame([self::SN], DB::table('devices')->pluck('serial_number')->all());
        $this->assertNull(DB::table('devices')->value('online'));
    }

    public function test_lookup_failure_warning_carries_only_serial_exception_class_and_mode(): void
    {
        $logged = $this->captureLogs();
        $this->failDeviceLookups();

        $this->get('/iclock/getrequest?SN='.self::SN)->assertOk();

        $warnings = array_values(array_filter(
            $logged(),
            fn (array $entry) => $entry['message'] === 'device auth: lookup failed'
        ));
        $this->assertCount(1, $warnings);

        $context = $warnings[0]['context'];
        $keys = array_keys($context);
        sort($keys);
        $this->assertSame(['exception', 'mode', 'sn'], $keys);
        $this->assertSame(self::SN, $context['sn']);
        $this->assertSame(QueryException::class, $context['exception']);
        $this->assertSame('log', $context['mode']);

        // Neither the driver message nor the failing SQL may reach any log line.
        $everything = json_encode($logged());
        $this->assertStringNotContainsString(self::DB_ERROR_MARKER, (string) $everything);
        $this->assertStringNotContainsString('enabled', (string) $everything);
    }

    public function test_lookup_failure_is_warned_once_per_serial_and_exception_within_the_window(): void
    {
        $logged = $this->captureLogs();
        $this->failDeviceLookups();

        for ($i = 0; $i < 5; $i++) {
            $this->get('/iclock/getrequest?SN='.self::SN)->assertOk();
        }
        $this->get('/iclock/getrequest?SN=FAKE-SN-OTHER')->assertOk();

        $warned = array_map(
            fn (array $entry) => $entry['context']['sn'] ?? null,
            array_values(array_filter(
                $logged(),
                fn (array $entry) => $entry['message'] === 'device auth: lookup failed'
            ))
        );

        $this->assertSame([self::SN, 'FAKE-SN-OTHER'], $warned);
    }

    /**
     * As on MySQL before the migration adding devices.enabled has run: any
     * query that reads that column fails. (Dropping the column is no good on
     * SQLite, which reads an unknown "enabled" as a string literal.) The
     * controller never reads it, so staging is unaffected.
     */
    private function failDeviceLookups(): void
    {
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection): void {
            if (str_contains($sql, 'devices') && str_contains($sql, 'enabled')) {
                throw new QueryException(
                    $connection->getName(),
                    $sql,
                    $bindings,
                    new \PDOException('SQLSTATE[42S22]: Column not found: 1054 '.self::DB_ERROR_MARKER)
                );
            }
        });
    }

    /**
     * @return \Closure(): list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function captureLogs(): \Closure
    {
        $entries = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$entries): void {
            $entries[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });

        return function () use (&$entries): array {
            return $entries;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function freshConfig(): array
    {
        return require config_path('adms.php');
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            unset($_ENV[self::ENV], $_SERVER[self::ENV]);
            putenv(self::ENV);

            return;
        }

        $_ENV[self::ENV] = $value;
        $_SERVER[self::ENV] = $value;
        putenv(self::ENV.'='.$value);
    }
}
