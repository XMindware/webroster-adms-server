<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClockCommTest extends TestCase
{
    use RefreshDatabase;

    private function office(): Oficina
    {
        return Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    private function device(string $sn = 'SN-1'): Device
    {
        return Device::create([
            'serial_number' => $sn,
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
            'name' => 'Test device',
        ]);
    }

    /**
     * A raw ATTLOG upload. The body is tab separated and arrives as a plain
     * body, not as form fields, so it has to go through call() to reach
     * $request->getContent() intact.
     */
    private function postAttlog(string $body, string $sn = 'SN-1')
    {
        return $this->call(
            'POST',
            "/iclock/cdata?SN={$sn}&table=ATTLOG",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $body
        );
    }

    /**
     * One attendance row inserted the way ingest writes it: created_at is "just
     * now", timestamp is the punch time the terminal claims.
     */
    private function attendance(\Carbon\Carbon $punchTime): void
    {
        DB::table('attendances')->insert([
            'sn' => 'SN-1',
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => 1,
            'timestamp' => $punchTime,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * test the clock/test
     */
    public function test_iclock_test_returns_200(): void
    {
        $response = $this->get('/iclock/test');

        $response->assertStatus(200);
    }


    /**
     * test the clock/cdata
     */
    public function test_iclock_getrequest_returns_200(): void
    {
        $response = $this->get('/iclock/getrequest');

        $response->assertStatus(200);
    }

    /**
     * The handshake is what keeps the "Online" column in the device list
     * truthful. If this regresses the list silently shows "Unknown" forever,
     * which is hard to tell apart from a device that is genuinely offline.
     */
    public function test_handshake_marks_the_device_online(): void
    {
        $this->office();
        $device = $this->device();

        $this->assertNull($device->online);

        $this->get('/iclock/cdata?SN=SN-1&options=all')->assertOk();

        $this->assertNotNull($device->fresh()->online, 'handshake must set devices.online');
    }

    /**
     * A terminal that has not been registered yet still handshakes. updateOrInsert
     * creates a row for it — which is how a serial number typo shows up as a
     * nameless duplicate device instead of an error.
     */
    public function test_handshake_for_an_unregistered_serial_number_creates_a_row(): void
    {
        $this->office();

        $this->get('/iclock/cdata?SN=UNKNOWN-SN&options=all')->assertOk();

        $this->assertNotNull(
            Device::where('serial_number', 'UNKNOWN-SN')->first(),
            'updateOrInsert registers an unknown serial number'
        );
    }

    /**
     * getrequest is the terminal's frequent keepalive, so it also refreshes
     * online. Unlike the handshake it does NOT create a row — an unknown serial
     * number gets an error string instead.
     */
    public function test_getrequest_refreshes_online_for_a_known_device(): void
    {
        $this->office();
        $device = $this->device();

        $this->get('/iclock/getrequest?SN=SN-1')->assertOk();

        $this->assertNotNull($device->fresh()->online);
    }

    public function test_getrequest_rejects_an_unknown_serial_number(): void
    {
        $this->office();

        $response = $this->get('/iclock/getrequest?SN=UNKNOWN-SN');

        $this->assertStringContainsString('Device not found', $response->getContent());
        $this->assertNull(Device::where('serial_number', 'UNKNOWN-SN')->first());
    }

    /**
     * OpStamp used to be sent as "Y-m-d H:i:s" and TimeZone as an IANA name.
     * Both are the wrong type for the protocol, and a terminal that cannot
     * parse the options block never settles into a normal polling rhythm.
     */
    public function test_handshake_response_uses_protocol_correct_types(): void
    {
        $this->office();
        $this->device();

        $body = $this->get('/iclock/cdata?SN=SN-1&options=all')->getContent();

        // OpStamp is a unix timestamp in seconds. Lines end with CRLF, so the
        // pattern has to tolerate the trailing \r.
        $this->assertSame(1, preg_match('/^OpStamp=(\d+)\r?$/m', $body, $m), 'OpStamp must be an integer');
        $this->assertGreaterThan(1_600_000_000, (int) $m[1]);
        $this->assertLessThan(2_000_000_000, (int) $m[1]);

        // TimeZone is an hour offset, never an IANA identifier. The working
        // deployments omit it entirely.
        $this->assertStringNotContainsString('TimeZone=Asia/Jakarta', $body);
        $this->assertStringNotContainsString('TimeZone=', $body);

        $this->assertStringContainsString('TransTimes=00:00;14:05', $body);
        $this->assertStringContainsString('Stamp=9999', $body);
        $this->assertStringContainsString('TransFlag=' . config('adms.trans_flag'), $body);
    }

    /**
     * The terminal posts to these endpoints without any CSRF token, because it
     * is not a browser. If one is missing from VerifyCsrfToken::$except the
     * device gets a 419 and stops pushing data — and from the outside that looks
     * identical to a network or firewall problem.
     */
    #[DataProvider('devicePostEndpoints')]
    public function test_device_post_endpoints_are_not_blocked_by_csrf(string $path): void
    {
        $this->office();
        $this->device();

        // Deliberately no token: a browser request would come back 419.
        $response = $this->post($path, []);
        $status = $response->getStatusCode();

        $this->assertNotSame(
            419,
            $status,
            "POST {$path} must be exempt from CSRF verification"
        );

        // The terminal must never see a server error, even for a malformed
        // payload. querydata used to 500 here on a request carrying no SN.
        $this->assertLessThan(
            500,
            $status,
            "POST {$path} must not raise a server error"
        );
    }

    /**
     * A terminal that cannot advance its upload watermark re-sends its whole
     * log. Ingest has to be idempotent, otherwise attendances grows on every
     * cycle - 10,389 rows for one device in a single day, 830 of them repeats,
     * on 2026-09-22.
     */
    public function test_replaying_the_same_attlog_does_not_insert_duplicates(): void
    {
        $this->office();
        $this->device();

        $body = "1\t2026-09-22 08:00:00\t0\t1\t0\t0\t0\r\n"
            . "2\t2026-09-22 08:05:00\t0\t1\t0\t0\t0\r\n";

        $this->postAttlog($body)->assertOk();
        $this->assertSame(2, Attendance::count());

        // The very same batch again, as a replaying terminal would send it.
        $this->postAttlog($body)->assertOk();

        $this->assertSame(2, Attendance::count(), 'a replayed batch must not insert duplicates');
    }

    /**
     * The reply count is the terminal's watermark. If it shrinks after dedup the
     * terminal concludes the batch failed and re-sends it forever, so the reply
     * has to keep reporting how many records the terminal sent.
     */
    public function test_attlog_reply_reports_records_sent_not_records_kept(): void
    {
        $this->office();
        $this->device();

        $body = "1\t2026-09-22 08:00:00\t0\t1\t0\t0\t0\r\n";

        $this->postAttlog($body);
        $second = $this->postAttlog($body);

        $this->assertSame('OK: 1', trim($second->getContent()));
        $this->assertSame(1, Attendance::count());
    }

    /**
     * A device replaying months of backlog is not a clock fault. Counting those
     * rows made the monitor card read 9,559 on 2026-09-22 while the terminal's
     * clock was fine, so the figure has to ignore them.
     */
    public function test_discrepancy_count_ignores_replayed_backlog(): void
    {
        $this->office();
        $device = $this->device();

        $this->attendance(now()->subDays(400));

        $this->assertSame(0, $device->getTimezoneDiscrepancyCount());
    }

    /**
     * A real clock fault - the terminal's idea of "now" is hours off - must
     * still be reported.
     */
    public function test_discrepancy_count_still_reports_a_real_clock_fault(): void
    {
        $this->office();
        $device = $this->device();

        $this->attendance(now()->subHours(3));

        $this->assertSame(1, $device->getTimezoneDiscrepancyCount());
    }

    /**
     * The correction is handed to the terminal and marked executed in the same
     * request, so it is never pending when the next poll arrives and a
     * pending-command check cannot throttle it. Without the cooldown this queued
     * a device_commands row on every poll (~30 s) - thousands a day.
     */
    public function test_clock_correction_is_not_queued_on_every_poll(): void
    {
        $this->office();
        $this->device();

        $this->attendance(now()->subHours(3));

        $this->get('/iclock/getrequest?SN=SN-1')->assertOk();
        $this->get('/iclock/getrequest?SN=SN-1')->assertOk();

        $this->assertSame(
            1,
            Command::where('data', 'like', '%SET OPTIONS DateTime=%')->count(),
            'the clock correction must be rate limited'
        );
    }

    /**
     * The monitor page renders one count per device. Calling the per-device
     * method in a loop meant one query per terminal; the batched variant must
     * produce byte-identical figures, or the page and the clock-correction
     * decision would disagree about the same fleet.
     */
    public function test_batched_discrepancy_counts_match_the_per_device_method(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $other = Device::create([
            'serial_number' => 'SN-2',
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '2',
            'name' => 'Second device',
        ]);

        // One genuine clock fault on SN-1 ...
        $this->attendance(now()->subHours(3));

        // ... a replaying backlog on SN-1, which must not count ...
        DB::table('attendances')->insert([
            'sn' => 'SN-1',
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => 1,
            'timestamp' => now()->subDays(400),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ... and a healthy punch on SN-2.
        DB::table('attendances')->insert([
            'sn' => 'SN-2',
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => 2,
            'timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batched = Device::discrepancyCountsFor(Device::all());

        $this->assertSame(1, $batched['SN-1']);
        $this->assertSame(0, $batched['SN-2']);

        foreach ([$device, $other] as $d) {
            $this->assertSame(
                $d->getTimezoneDiscrepancyCount(),
                $batched[$d->serial_number],
                "batched count for {$d->serial_number} must match the per-device method"
            );
        }
    }

    /**
     * quickStatus reads the newest error_log row. created_at comes back from the
     * query builder as a plain string, and calling ->toIso8601String() on it
     * used to fatal - so the endpoint 500'd as soon as there was one row to
     * report, which is exactly when it matters.
     */
    public function test_quick_status_reports_the_newest_error_with_a_parsable_timestamp(): void
    {
        DB::table('error_log')->insert([
            'data' => 'Something went wrong on the terminal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/api/test');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'error');

        $timestamp = $response->json('timestamp');

        $this->assertNotEmpty($timestamp, 'an error report must carry a timestamp');
        $this->assertSame(
            $timestamp,
            \Carbon\Carbon::parse($timestamp)->toIso8601String(),
            'the timestamp must be a valid ISO 8601 instant'
        );
    }

    public function test_quick_status_reports_ok_when_no_errors_are_logged(): void
    {
        $response = $this->get('/api/test');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'ok');
    }

    public static function devicePostEndpoints(): array
    {
        return [
            'cdata' => ['/iclock/cdata'],
            'devicecmd' => ['/iclock/devicecmd'],
            'querydata' => ['/iclock/querydata'],
            'upload-log' => ['/iclock/upload-log'],
        ];
    }
}
