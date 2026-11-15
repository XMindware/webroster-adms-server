<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\Device;
use App\Models\EmpresaApiKey;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $sn, string $empresa, ?\DateTimeInterface $online, int $oficina = 1): Device
    {
        $device = Device::create([
            'serial_number' => $sn,
            'idempresa' => $empresa,
            'idoficina' => $oficina,
            'idreloj' => '1',
        ]);
        // `online` is fillable but updated_at handling is DB-side; set directly.
        DB::table('devices')->where('id', $device->id)->update(['online' => $online]);

        return $device->fresh();
    }

    private function checada(string $sn, int $employee, string $timestamp): void
    {
        DB::table('attendances')->insert([
            'sn' => $sn, 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => $employee,
            'timestamp' => $timestamp, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function headers(string $key): array
    {
        return ['Authorization' => "Bearer $key"];
    }

    public function test_requires_a_valid_key(): void
    {
        $this->getJson('/api/empresa/devices')->assertStatus(401);
        $this->getJson('/api/empresa/devices', $this->headers('wrk_nope'))->assertStatus(401);
    }

    public function test_revoked_key_is_rejected(): void
    {
        [$key, $plain] = EmpresaApiKey::issue('10');
        $key->update(['revoked_at' => now()]);

        $this->getJson('/api/empresa/devices', $this->headers($plain))->assertStatus(401);
    }

    public function test_key_is_stored_hashed(): void
    {
        [$key, $plain] = EmpresaApiKey::issue('10');

        $this->assertNotEquals($plain, $key->key_hash);
        $this->assertDatabaseMissing('empresa_api_keys', ['key_hash' => $plain]);
    }

    public function test_lists_only_the_callers_empresa_with_status(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');

        Oficina::create(['idempresa' => 10, 'idoficina' => 1, 'ubicacion' => 'Cancun', 'public_url' => 'https://cun.test', 'iatacode' => 'CUN', 'timezone' => 'America/Cancun']);
        $on = $this->device('SN-ON', '10', now()->subMinute());
        $this->device('SN-OFF', '10', now()->subHour());
        $this->device('SN-NEVER', '10', null);
        $this->device('SN-OTHER', '99', now());

        $this->checada('SN-ON', 5, '2026-10-08 08:01:02');
        $this->checada('SN-ON', 7, '2026-10-08 09:15:00');
        Command::create(['device_id' => $on->id, 'command' => 'C:1:CHECK', 'data' => '']);

        $response = $this->getJson('/api/empresa/devices', $this->headers($plain))->assertOk();

        $response->assertJsonPath('idempresa', '10')
            ->assertJsonPath('summary', ['total' => 3, 'online' => 1, 'offline' => 2])
            ->assertJsonMissing(['serial_number' => 'SN-OTHER']);

        $rows = collect($response->json('data'))->keyBy('serial_number');
        $this->assertTrue($rows['SN-ON']['online']);
        $this->assertFalse($rows['SN-OFF']['online']);
        $this->assertFalse($rows['SN-NEVER']['online']);
        $this->assertNull($rows['SN-NEVER']['last_seen_at']);
        $this->assertSame('Cancun', $rows['SN-ON']['oficina']);
        $this->assertSame(1, $rows['SN-ON']['pending_commands']);
        $this->assertSame(7, $rows['SN-ON']['last_checada']['employee_id']);
        $this->assertSame('2026-10-08 09:15:00', $rows['SN-ON']['last_checada']['device_timestamp']);
        $this->assertNull($rows['SN-OFF']['last_checada']);
    }

    public function test_online_filter(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->device('SN-ON', '10', now());
        $this->device('SN-OFF', '10', now()->subDay());

        $this->getJson('/api/empresa/devices?online=1', $this->headers($plain))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'SN-ON')
            ->assertJsonPath('summary.total', 2);
    }

    public function test_show_returns_device_and_hides_other_empresas(): void
    {
        [, $plain] = EmpresaApiKey::issue('10');
        $this->device('SN-MINE', '10', now());
        $this->device('SN-THEIRS', '99', now());

        $this->getJson('/api/empresa/devices/SN-MINE', $this->headers($plain))
            ->assertOk()
            ->assertJsonPath('data.serial_number', 'SN-MINE')
            ->assertJsonPath('data.online', true);

        $this->getJson('/api/empresa/devices/SN-THEIRS', $this->headers($plain))->assertNotFound();
        $this->getJson('/api/empresa/devices/NOPE', $this->headers($plain))->assertNotFound();
    }

    public function test_artisan_commands_issue_and_revoke(): void
    {
        $this->artisan('empresa-keys:create', ['idempresa' => '10', '--name' => 'webroster'])->assertSuccessful();
        $key = EmpresaApiKey::firstOrFail();
        $this->assertSame('10', $key->idempresa);

        $this->artisan('empresa-keys:revoke', ['id' => $key->id])->assertSuccessful();
        $this->assertNotNull($key->fresh()->revoked_at);
    }
}
