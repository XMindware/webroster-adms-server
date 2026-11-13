<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Device;
use App\Models\DeviceBackup;
use App\Models\FingerprintTemplate;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The per-terminal backup: one click on a device row writes a snapshot of
 * everything the server holds for that terminal and hands the file over.
 *
 * What is pinned here is mostly the file, not the screen - the point of the
 * feature is that the archive is complete and readable, and that it survives
 * the device being deleted afterwards.
 */
class DeviceBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshots land on the `local` disk. Faking it keeps test files out of
        // storage/app and lets the assertions read back what was written.
        Storage::fake('local');
    }

    private function office(): Oficina
    {
        return Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Jakarta',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'JKT',
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
            'name' => 'Pintu Utama',
        ]);
    }

    private function agent(string $pin = '1234'): Agente
    {
        return Agente::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'idagente' => $pin,
            'shortname' => 'Budi',
            'fullname' => 'Budi Santoso',
        ]);
    }

    private function template(Device $device, string $pin = '1234'): FingerprintTemplate
    {
        return FingerprintTemplate::create([
            'device_id' => $device->id,
            'sn' => $device->serial_number,
            'pin' => $pin,
            'fid' => 0,
            'size' => 12,
            'valid' => 1,
            'template' => 'QUJDREVGRw==',
            'template_hash' => hash('sha256', 'QUJDREVGRw=='),
            'idempresa' => $device->idempresa,
            'idoficina' => $device->idoficina,
            'source' => 'query',
            'captured_at' => now(),
        ]);
    }

    private function punch(Device $device, string $pin = '1234'): void
    {
        DB::table('attendances')->insert([
            'sn' => $device->serial_number,
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => (int) $pin,
            'timestamp' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingUser(): self
    {
        return $this->actingAs(User::factory()->create());
    }

    public function test_backup_writes_a_snapshot_and_downloads_it(): void
    {
        $this->office();
        $device = $this->device();
        $this->agent();
        $this->template($device);
        $this->punch($device);

        $response = $this->actingUser()->post("/devices/{$device->id}/backup");

        // The download is the response: one click, one file.
        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');

        $backup = DeviceBackup::firstOrFail();

        $this->assertSame($device->id, $backup->device_id);
        $this->assertSame('SN-1', $backup->serial_number);
        $this->assertSame(1, $backup->employees_count);
        $this->assertSame(1, $backup->fingerprints_count);
        $this->assertSame(1, $backup->attendances_count);
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertNotNull($backup->created_by);

        Storage::disk('local')->assertExists($backup->file_name);
    }

    public function test_the_snapshot_holds_the_whole_terminal(): void
    {
        $this->office();
        $device = $this->device();
        $this->agent();
        $this->template($device);
        $this->punch($device);

        $this->actingUser()->post("/devices/{$device->id}/backup");

        $backup = DeviceBackup::firstOrFail();
        $payload = json_decode(Storage::disk('local')->get($backup->file_name), true);

        $this->assertSame('adms-device-backup', $payload['format']);
        $this->assertSame(1, $payload['version']);

        // The device and its office travel with the snapshot: a file found
        // later has to say which terminal it came from and where it stood.
        $this->assertSame('SN-1', $payload['device']['serial_number']);
        $this->assertSame('Pintu Utama', $payload['device']['name']);
        $this->assertSame('Jakarta', $payload['office']['ubicacion']);

        // Cast to string: SQLite hands an integer column back as int while
        // MySQL returns the varchar, and the backup stores what it was given.
        $this->assertSame('1234', (string) $payload['employees'][0]['idagente']);
        $this->assertSame('Budi Santoso', $payload['employees'][0]['fullname']);

        // The template payload has to come back byte for byte - a backup that
        // loses the template is a backup of nothing.
        $this->assertSame('QUJDREVGRw==', $payload['fingerprint_templates'][0]['template']);

        $this->assertCount(1, $payload['attendances']);
        $this->assertSame('SN-1', $payload['attendances'][0]['sn']);

        $this->assertSame(1, $payload['counts']['employees']);
        $this->assertSame(1, $payload['counts']['fingerprints']);
        $this->assertSame(1, $payload['counts']['attendances']);
    }

    public function test_the_snapshot_only_holds_this_terminals_biometrics(): void
    {
        $this->office();
        $mine = $this->device('SN-1');
        $other = $this->device('SN-2');

        $this->template($mine, '1111');
        $this->template($other, '2222');

        $this->actingUser()->post("/devices/{$mine->id}/backup");

        $backup = DeviceBackup::firstOrFail();
        $payload = json_decode(Storage::disk('local')->get($backup->file_name), true);

        $this->assertSame(1, $backup->fingerprints_count);
        $this->assertSame('1111', $payload['fingerprint_templates'][0]['pin']);
    }

    public function test_backup_of_an_unknown_device_reports_the_standard_error(): void
    {
        $response = $this->actingUser()->post('/devices/999/backup');

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('error', __('devices.device_not_found'));
        $this->assertSame(0, DeviceBackup::count());
    }

    public function test_a_stored_backup_can_be_downloaded_again(): void
    {
        $this->office();
        $device = $this->device();

        $this->actingUser()->post("/devices/{$device->id}/backup");
        $backup = DeviceBackup::firstOrFail();

        $response = $this->actingUser()->get(route('devices.backups.download', ['id' => $backup->id]));

        $response->assertOk();
        $this->assertStringContainsString('"serial_number":"SN-1"', $response->streamedContent());
    }

    public function test_a_backup_whose_file_has_gone_is_reported_not_downloaded(): void
    {
        $this->office();
        $device = $this->device();

        $this->actingUser()->post("/devices/{$device->id}/backup");
        $backup = DeviceBackup::firstOrFail();

        // Storage can be wiped independently of the table (a deploy, a hand
        // deleting the folder), so the row outliving its file is normal.
        Storage::disk('local')->delete($backup->file_name);

        $response = $this->actingUser()->get(route('devices.backups.download', ['id' => $backup->id]));

        $response->assertRedirect(route('devices.backups'));
        $response->assertSessionHas('error', __('devices.backup_file_missing'));
    }

    public function test_a_backup_can_be_deleted_with_its_file(): void
    {
        $this->office();
        $device = $this->device();

        $this->actingUser()->post("/devices/{$device->id}/backup");
        $backup = DeviceBackup::firstOrFail();
        $fileName = $backup->file_name;

        $response = $this->actingUser()->post(route('devices.backups.delete', ['id' => $backup->id]));

        $response->assertRedirect(route('devices.backups'));
        $response->assertSessionHas('success', __('devices.backup_deleted'));

        $this->assertDatabaseMissing('device_backups', ['id' => $backup->id]);
        Storage::disk('local')->assertMissing($fileName);
    }

    /**
     * The whole reason device_id is not a foreign key: the archive is most
     * useful after the terminal it came from is gone.
     */
    public function test_the_snapshot_outlives_the_device_it_came_from(): void
    {
        $this->office();
        $device = $this->device();

        $this->actingUser()->post("/devices/{$device->id}/backup");
        $backup = DeviceBackup::firstOrFail();

        $this->actingUser()->get(route('devices.delete', ['id' => $device->id]))
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseHas('device_backups', ['id' => $backup->id, 'serial_number' => 'SN-1']);
        Storage::disk('local')->assertExists($backup->file_name);
    }

    public function test_the_backups_page_lists_the_snapshots(): void
    {
        $this->office();
        $device = $this->device();

        $this->actingUser()->post("/devices/{$device->id}/backup");

        $response = $this->actingUser()->get(route('devices.backups'));

        $response->assertOk();
        $response->assertSee(__('devices.backups_title'), false);
        $response->assertSee('Pintu Utama', false);
        $response->assertSee('SN-1', false);
    }

    public function test_the_backups_page_says_so_when_there_is_nothing_yet(): void
    {
        $response = $this->actingUser()->get(route('devices.backups'));

        $response->assertOk();
        $response->assertSee(__('devices.no_backups_yet'), false);
    }

    /**
     * The button is the feature as far as an operator is concerned, so it has
     * to be on the device row and it has to be translated.
     */
    public function test_the_device_row_offers_a_backup_button_in_every_locale(): void
    {
        $this->office();
        $device = $this->device();

        foreach (['en', 'es', 'id'] as $locale) {
            $response = $this->actingUser()->get("/devices?lang={$locale}");

            $response->assertOk();
            $response->assertSee(route('devices.backup', ['id' => $device->id]), false);
            $response->assertSee(__('devices.backup_device', [], $locale), false);
        }
    }
}
