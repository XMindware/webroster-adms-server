<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * S2: /iclock/* must only serve registered, enabled serial numbers.
 * ADMS_DEVICE_AUTH=log reports unknown serials but still serves them, so
 * the live fleet can be checked before switching to enforce.
 */
class DeviceAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Oficina::create([
            'idempresa' => 1, 'idoficina' => 2, 'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test', 'token' => 'token', 'iatacode' => 'CUN',
            'timezone' => 'America/Cancun',
        ]);
        Device::create(['serial_number' => 'SN-OK', 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);
    }

    private function enforce(): void
    {
        config(['adms.device_auth' => 'enforce']);
    }

    private function postRaw(string $uri, string $body)
    {
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_default_mode_is_log_only(): void
    {
        $this->assertSame('log', config('adms.device_auth'));
    }

    public function test_log_mode_still_serves_unknown_serials_and_reports_them(): void
    {
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$warnings) {
            if ($e->level === 'warning') {
                $warnings[] = $e->message.' '.json_encode($e->context);
            }
        });

        $this->get('/iclock/cdata?SN=SN-NEW&options=all')->assertOk();

        $this->assertNotEmpty(array_filter($warnings, fn ($w) => str_contains($w, 'SN-NEW')));
    }

    public function test_enforce_rejects_unknown_serials_on_every_device_endpoint(): void
    {
        $this->enforce();

        $this->get('/iclock/cdata?SN=SN-EVIL&options=all')->assertForbidden();
        $this->postRaw('/iclock/cdata?SN=SN-EVIL&table=ATTLOG', "1234\t2026-11-15 10:00:00\t0\t1")->assertForbidden();
        $this->postRaw('/iclock/cdata?SN=SN-EVIL&table=OPERLOG', "FP PIN=1\tFID=0\tSize=4\tValid=1\tTMP=QQ==")->assertForbidden();
        $this->get('/iclock/getrequest?SN=SN-EVIL')->assertForbidden();
        $this->postRaw('/iclock/devicecmd?SN=SN-EVIL', 'ID=1&Return=0&CMD=DATA')->assertForbidden();
        $this->get('/iclock/rtdata?SN=SN-EVIL')->assertForbidden();
        $this->postRaw('/iclock/querydata?SN=SN-EVIL&tablename=biodata', "BIODATA Pin=1\tNo=0\tType=9\tTmp=QQ==")->assertForbidden();
        $this->post('/iclock/upload-log', ['sn' => 'SN-EVIL'])->assertForbidden();

        $this->assertDatabaseMissing('devices', ['serial_number' => 'SN-EVIL']);
        $this->assertSame(0, DB::table('attendances')->count());
        $this->assertSame(0, DB::table('fingerprint_templates')->count());
    }

    public function test_enforce_rejects_requests_without_a_serial_number(): void
    {
        $this->enforce();

        $this->get('/iclock/cdata?options=all')->assertForbidden();
        $this->get('/iclock/getrequest')->assertForbidden();
    }

    public function test_enforce_rejects_disabled_devices(): void
    {
        $this->enforce();
        Device::where('serial_number', 'SN-OK')->update(['enabled' => false]);

        $this->get('/iclock/cdata?SN=SN-OK&options=all')->assertForbidden();
        $this->postRaw('/iclock/cdata?SN=SN-OK&table=ATTLOG', "1234\t2026-11-15 10:00:00\t0\t1")->assertForbidden();

        $this->assertSame(0, DB::table('attendances')->count());
    }

    public function test_registered_device_gets_the_same_handshake_in_both_modes(): void
    {
        $logMode = $this->get('/iclock/cdata?SN=SN-OK&options=all')->assertOk()->getContent();

        $this->enforce();
        $enforced = $this->get('/iclock/cdata?SN=SN-OK&options=all')->assertOk()->getContent();

        $this->assertStringStartsWith("GET OPTION FROM: SN-OK\r\n", $enforced);
        // OpStamp carries the current time; compare everything else.
        $strip = fn (string $r) => preg_replace('/OpStamp=[^\r\n]*/', 'OpStamp=X', $r);
        $this->assertSame($strip($logMode), $strip($enforced));
    }

    public function test_enforce_accepts_punches_from_a_registered_device(): void
    {
        $this->enforce();

        $this->postRaw('/iclock/cdata?SN=SN-OK&table=ATTLOG', "1234\t2026-11-15 10:00:00\t0\t1")
            ->assertOk()
            ->assertSeeText('OK: 1');

        $this->assertSame(1, DB::table('attendances')->where('sn', 'SN-OK')->count());
    }

    public function test_device_endpoints_are_rate_limited_per_serial(): void
    {
        config(['adms.device_rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->get('/iclock/getrequest?SN=SN-OK')->assertOk();
        }
        $this->get('/iclock/getrequest?SN=SN-OK')->assertStatus(429);
    }

    public function test_new_devices_are_enabled_by_default(): void
    {
        $this->assertTrue((bool) Device::where('serial_number', 'SN-OK')->firstOrFail()->enabled);
    }

    public function test_admin_can_disable_a_device_from_the_edit_form(): void
    {
        $device = Device::where('serial_number', 'SN-OK')->firstOrFail();
        $office = Oficina::firstOrFail();

        $this->actingAs(User::factory()->create())
            ->post("/devices/{$device->id}/update", [
                'name' => 'Front door', 'serial_number' => 'SN-OK', 'idreloj' => '1',
                'oficina_id' => $office->id, 'enabled' => '0',
            ])->assertRedirect(route('devices.index'));

        $this->assertFalse($device->fresh()->enabled);

        $this->enforce();
        $this->get('/iclock/getrequest?SN=SN-OK')->assertForbidden();
    }
}
