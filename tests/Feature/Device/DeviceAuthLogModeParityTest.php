<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2, Phase 1 decision (2026-11-17): with ADMS_DEVICE_AUTH at its default
 * (log), /iclock/* must behave exactly like staging. "Staging" here is the
 * same request with AuthenticateDevice and the device throttle taken out of
 * the pipeline. Status, body, headers and stored rows must all match, for
 * registered, disabled, unregistered, missing and conflicting serials.
 *
 * Each side runs on a fresh application and a freshly migrated in-memory
 * database, with the clock frozen, so the two runs see identical state.
 */
class DeviceAuthLogModeParityTest extends TestCase
{
    private const NOW = '2026-11-15 10:00:00';

    private const SN_OK = 'FAKE-SN-OK';

    private const SN_OFF = 'FAKE-SN-OFF';

    private const SN_NEW = 'FAKE-SN-NEW';

    private const SN_OTHER = 'FAKE-SN-OTHER';

    /** Tables an /iclock request can write to. */
    private const TABLES = [
        'devices', 'attendances', 'log_entries', 'device_log', 'finger_log',
        'error_log', 'device_commands', 'device_options',
        'fingerprint_templates', 'face_templates', 'face_photos',
    ];

    /**
     * Filled by the database (useCurrent) from the wall clock, which
     * Carbon::setTestNow does not control. Not part of the contract.
     */
    private const WALL_CLOCK_COLUMNS = ['created_at', 'updated_at'];

    private const ROUTES = [
        'cdata GET', 'cdata POST ATTLOG', 'getrequest', 'rtdata',
        'devicecmd', 'querydata', 'upload-log', 'test',
    ];

    /** Cases in which log mode reports the serial, and so touches the cache. */
    private const REPORTED_CASES = ['unregistered', 'no SN', 'disabled'];

    private const CASES = ['registered', 'disabled', 'unregistered', 'no SN', 'conflicting SN'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requests(): array
    {
        $data = [];
        foreach (self::ROUTES as $route) {
            foreach (self::CASES as $case) {
                $data["$route / $case"] = [$route, $case];
            }
        }

        return $data;
    }

    /**
     * @dataProvider requests
     */
    public function test_log_mode_matches_the_pipeline_without_device_auth(string $route, string $case): void
    {
        $staging = $this->capture($route, $case, bypassDeviceAuth: true);
        $actual = $this->capture($route, $case, bypassDeviceAuth: false);

        $this->assertSame($staging['status'], $actual['status'], 'status differs from staging');
        $this->assertSame($staging['body'], $actual['body'], 'body differs from staging');
        $this->assertSame($staging['rows'], $actual['rows'], 'stored rows differ from staging');
        $this->assertSame($staging['headers'], $actual['headers'], 'headers differ from staging');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function reportedRequests(): array
    {
        $data = [];
        foreach (self::ROUTES as $route) {
            foreach (self::REPORTED_CASES as $case) {
                $data["$route / $case"] = [$route, $case];
            }
        }

        return $data;
    }

    /**
     * Reporting an unknown serial is bookkeeping: if the cache that dedupes
     * the warning is down, log mode must still answer exactly like staging.
     *
     * @dataProvider reportedRequests
     */
    public function test_log_mode_matches_staging_when_the_warning_cache_fails(string $route, string $case): void
    {
        // Only add() fails, so the controller's own cache use (pending
        // full-resend flags) still works on both sides.
        $cacheDown = function (): void {
            Cache::extend('add-fails', fn () => new class(new ArrayStore) extends Repository
            {
                public function add($key, $value, $ttl = null)
                {
                    throw new \RuntimeException('fake cache outage');
                }
            });
            config([
                'cache.stores.add-fails' => ['driver' => 'add-fails'],
                'cache.default' => 'add-fails',
            ]);
        };

        $staging = $this->capture($route, $case, bypassDeviceAuth: true, arrange: $cacheDown);
        $actual = $this->capture($route, $case, bypassDeviceAuth: false, arrange: $cacheDown);

        $this->assertSame($staging['status'], $actual['status'], 'status differs from staging');
        $this->assertSame($staging['body'], $actual['body'], 'body differs from staging');
        $this->assertSame($staging['rows'], $actual['rows'], 'stored rows differ from staging');
    }

    /**
     * @param  (\Closure(): void)|null  $arrange  runs on the fresh app, before the request
     * @return array{status: int, body: string, headers: array<string, mixed>, rows: array<string, mixed>}
     */
    private function capture(string $route, string $case, bool $bypassDeviceAuth, ?\Closure $arrange = null): array
    {
        $this->refreshApplication();
        Carbon::setTestNow(self::NOW);
        Artisan::call('migrate');
        $this->seedFleet();

        if ($arrange !== null) {
            $arrange();
        }

        if ($bypassDeviceAuth) {
            $this->withoutMiddleware([AuthenticateDevice::class, ThrottleRequests::class]);
        }

        [$method, $uri, $params, $files, $server, $content] = $this->request($route, $case);
        $response = $this->call($method, $uri, $params, [], $files, $server, $content);

        $headers = $response->headers->all();
        unset($headers['date'], $headers['set-cookie']);
        ksort($headers);

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
            'headers' => $headers,
            'rows' => $this->rows(),
        ];
    }

    private function seedFleet(): void
    {
        Oficina::create([
            'idempresa' => 1, 'idoficina' => 2, 'ubicacion' => 'Fake Station',
            'public_url' => 'https://station.test', 'token' => 'fake-token',
            'iatacode' => 'XXX', 'timezone' => 'America/Cancun',
        ]);

        $ok = Device::create(['serial_number' => self::SN_OK, 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);
        $off = Device::create(['serial_number' => self::SN_OFF, 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '2']);
        DB::table('devices')->where('id', $off->id)->update(['enabled' => false]);

        // Same command number on both devices, so an ack routed by serial
        // shows which device the server believed it was talking to.
        foreach ([$ok, $off] as $device) {
            Command::create([
                'device_id' => $device->id,
                'command' => 501,
                'data' => 'C:501:DATA QUERY tablename=user,fielddesc=*,filter=*',
            ]);
        }
    }

    /**
     * @return array{string, string, array<string, mixed>, array<string, mixed>, array<string, string>, ?string}
     */
    private function request(string $route, string $case): array
    {
        $querySn = match ($case) {
            'registered', 'conflicting SN' => self::SN_OK,
            'disabled' => self::SN_OFF,
            'unregistered' => self::SN_NEW,
            'no SN' => null,
        };
        $conflict = $case === 'conflicting SN';

        $query = fn (array $extra) => http_build_query(array_filter(['SN' => $querySn] + $extra, fn ($v) => $v !== null));

        // A conflicting serial travels in the body: JSON on GET (the only
        // body Laravel reads on GET), form fields on POST.
        $json = ['CONTENT_TYPE' => 'application/json'];
        $text = ['CONTENT_TYPE' => 'text/plain'];
        $otherJson = (string) json_encode(['SN' => self::SN_OTHER]);

        return match ($route) {
            'cdata GET' => $conflict
                ? ['GET', '/iclock/cdata?'.$query(['options' => 'all', 'pushver' => '2.4.1']), [], [], $json, $otherJson]
                : ['GET', '/iclock/cdata?'.$query(['options' => 'all', 'pushver' => '2.4.1']), [], [], [], null],
            'cdata POST ATTLOG' => $conflict
                ? ['POST', '/iclock/cdata?'.$query(['table' => 'ATTLOG', 'Stamp' => '9999']), ['SN' => self::SN_OTHER], [], [], null]
                : ['POST', '/iclock/cdata?'.$query(['table' => 'ATTLOG', 'Stamp' => '9999']), [], [], $text,
                    "1001\t2026-11-15 09:58:00\t0\t1\t0\t0\r\n1002\t2026-11-15 09:59:00\t1\t1\t0\t0\r\n"],
            'getrequest' => $conflict
                ? ['GET', '/iclock/getrequest?'.$query([]), [], [], $json, $otherJson]
                : ['GET', '/iclock/getrequest?'.$query([]), [], [], [], null],
            'rtdata' => $conflict
                ? ['GET', '/iclock/rtdata?'.$query(['type' => 'time']), [], [], $json, $otherJson]
                : ['GET', '/iclock/rtdata?'.$query(['type' => 'time']), [], [], [], null],
            'test' => $conflict
                ? ['GET', '/iclock/test?'.$query([]), [], [], $json, $otherJson]
                : ['GET', '/iclock/test?'.$query([]), [], [], [], null],
            'devicecmd' => $conflict
                ? ['POST', '/iclock/devicecmd?'.$query([]), ['SN' => self::SN_OTHER, 'ID' => '501', 'Return' => '0', 'CMD' => 'DATA'], [], [], null]
                : ['POST', '/iclock/devicecmd?'.$query([]), [], [], $text, 'ID=501&Return=0&CMD=DATA'],
            'querydata' => $conflict
                ? ['POST', '/iclock/querydata?'.$query(['type' => 'BioData', 'tablename' => 'biodata', 'count' => '1']), ['SN' => self::SN_OTHER], [], [], null]
                : ['POST', '/iclock/querydata?'.$query(['type' => 'BioData', 'tablename' => 'biodata', 'count' => '1']), [], [], $text,
                    "biodata pin=9001\tno=0\tindex=0\tvalid=1\tduress=0\ttype=9\tmajorver=5\tminorver=8\tformat=0\ttmp=RkFLRQ=="],
            // upload-log carries its serial as a lowercase "sn" form field.
            'upload-log' => [
                'POST',
                '/iclock/upload-log'.($conflict ? '?SN='.self::SN_OK : ''),
                array_filter(['sn' => $conflict ? self::SN_OTHER : $querySn], fn ($v) => $v !== null),
                ['file' => UploadedFile::fake()->createWithContent(
                    'fake.log',
                    "2026-11-15 09:00:00 - INFO - fake boot\nfake line without a timestamp\n"
                )],
                [],
                null,
            ],
        };
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => array_diff_key((array) $row, array_flip(self::WALL_CLOCK_COLUMNS)))
                ->all();
        }

        return $rows;
    }
}
