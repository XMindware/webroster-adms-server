<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FingerprintTemplate;
use App\Models\Oficina;
use App\Models\User;
use App\Services\Adms\AdmsProtocol;
use App\Services\DeviceMigrationService;
use App\Services\PullFingerprintsService;
use App\Services\PushFingerprintsService;
use App\Services\RemoveFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ADMS operations that had no first-class action: asking a terminal about
 * one employee, setting its clock on demand, moving an office's enrolment onto
 * a replacement terminal, and removing individual fingers without deleting the
 * person.
 *
 * The common thread is that all four are *queued*, never executed inline — a
 * terminal only ever learns about them on its next poll. Most of what these
 * tests pin down is therefore "the right row landed in device_commands", plus
 * the guards that stop a nonsensical request from queueing anything at all.
 */
class DeviceOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function office(int $idempresa = 1, int $idoficina = 2, string $timezone = 'Asia/Jakarta'): Oficina
    {
        return Oficina::create([
            'idempresa' => $idempresa,
            'idoficina' => $idoficina,
            'ubicacion' => 'Jakarta',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'JKT',
            'timezone' => $timezone,
        ]);
    }

    private function device(string $sn = 'SN-1', int $idempresa = 1, int $idoficina = 2): Device
    {
        return Device::create([
            'serial_number' => $sn,
            'idempresa' => $idempresa,
            'idoficina' => $idoficina,
            'idreloj' => '1',
            'name' => $sn,
        ]);
    }

    private function actingUser(): self
    {
        return $this->actingAs(User::factory()->create());
    }

    private function template(Device $device, string $pin, int $fid = 0): FingerprintTemplate
    {
        return FingerprintTemplate::create([
            'device_id' => $device->id,
            'sn' => $device->serial_number,
            'pin' => $pin,
            'fid' => $fid,
            'size' => 12,
            'valid' => 1,
            'template' => 'QUJDREVGRw==',
            'template_hash' => hash('sha256', 'QUJDREVGRw=='),
            'idempresa' => $device->idempresa,
            'idoficina' => $device->idoficina,
            'source' => 'pull',
            'captured_at' => now(),
        ]);
    }

    /**
     * A roster row. populate() reads the office roster from this table, so a
     * migration with no agents has nothing to queue.
     */
    private function agent(string $pin = '1234'): \App\Models\Agente
    {
        return \App\Models\Agente::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'idagente' => $pin,
            'shortname' => 'Budi',
            'fullname' => 'Budi Santoso',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Get info user
    |--------------------------------------------------------------------------
    */

    public function test_user_info_queues_a_targeted_userinfo_query(): void
    {
        $this->office();
        $device = $this->device();

        $queued = app(PullFingerprintsService::class)->userInfo($device, '1234', false);

        $this->assertSame(1, $queued);

        $command = $device->commands()->first();

        $this->assertSame("C:{$command->command}:DATA QUERY USERINFO PIN=1234", $command->data);
        $this->assertSame(PullFingerprintsService::TYPE_QUERY_USER, $command->type);
        $this->assertSame('PIN:1234', $command->reference);
    }

    /**
     * Asking for "the whole person" is the default: the user record plus one
     * query per finger slot.
     */
    public function test_user_info_also_asks_for_templates_by_default(): void
    {
        $this->office();
        $device = $this->device();

        $queued = app(PullFingerprintsService::class)->userInfo($device, '1234');

        $fingers = count(config('adms.finger_ids'));

        $this->assertSame(1 + $fingers, $queued);
        $this->assertSame(
            1,
            $device->commands()->where('type', PullFingerprintsService::TYPE_QUERY_USER)->count()
        );
        $this->assertSame(
            $fingers,
            $device->commands()->where('type', PullFingerprintsService::TYPE_QUERY_FP)->count()
        );
    }

    /**
     * A second click must not double the queue — same dedupe rule the rest of
     * the pull pipeline follows.
     */
    public function test_user_info_is_not_queued_twice_while_pending(): void
    {
        $this->office();
        $device = $this->device();
        $service = app(PullFingerprintsService::class);

        $service->userInfo($device, '1234', false);
        $second = $service->userInfo($device, '1234', false);

        $this->assertSame(0, $second);
        $this->assertSame(1, $device->commands()->count());
    }

    public function test_user_info_ignores_an_empty_pin(): void
    {
        $this->office();
        $device = $this->device();

        $this->assertSame(0, app(PullFingerprintsService::class)->userInfo($device, ''));
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_the_query_user_screen_queues_and_redirects(): void
    {
        $this->office();
        $device = $this->device();

        $response = $this->actingUser()->post('/devices/query-user', [
            'device' => $device->id,
            'pin' => '4321',
            'with_templates' => '1',
        ]);

        $response->assertRedirect(route('devices.queryUser'));
        $response->assertSessionHas('success');

        $this->assertSame(1, $device->commands()->where('reference', 'PIN:4321')->count());
    }

    public function test_the_query_user_screen_rejects_a_missing_pin(): void
    {
        $this->office();
        $device = $this->device();

        $response = $this->actingUser()->post('/devices/query-user', [
            'device' => $device->id,
            'pin' => '   ',
        ]);

        $response->assertRedirect(route('devices.queryUser'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('device_commands', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Set the clock on demand
    |--------------------------------------------------------------------------
    */

    public function test_setting_the_time_queues_a_clock_command_built_from_the_office_timezone(): void
    {
        $this->office(1, 2, 'Asia/Jakarta');
        $device = $this->device();

        $response = $this->actingUser()->get("/devices/{$device->id}/set-time");

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('success');

        $command = $device->commands()->first();

        $this->assertNotNull($command);
        $this->assertSame('set_datetime', $command->type);
        $this->assertStringStartsWith(
            'C:' . $command->command . ':SET OPTIONS DateTime=',
            $command->data
        );

        // The packed value must correspond to the office's local time, not UTC.
        $encoded = (int) substr($command->data, strrpos($command->data, '=') + 1);
        $this->assertSame(\App\Services\Adms\AdmsProtocol::encodeDateTime(now('Asia/Jakarta')), $encoded);
    }

    /**
     * The automatic correction refuses to build a clock from a generic zone,
     * because the terminal obeys and then reads as skewed forever. The manual
     * action has to refuse for the same reason — and it must not queue anything.
     */
    public function test_setting_the_time_is_refused_when_the_office_timezone_is_generic(): void
    {
        $this->office(1, 2, 'UTC');
        $device = $this->device();

        $response = $this->actingUser()->get("/devices/{$device->id}/set-time");

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_setting_the_time_on_an_unknown_device_reports_not_found(): void
    {
        $response = $this->actingUser()->get('/devices/999/set-time');

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('device_commands', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Migration
    |--------------------------------------------------------------------------
    */

    public function test_migration_queues_the_roster_and_the_templates_on_the_target(): void
    {
        $this->office();
        $source = $this->device('SN-OLD');
        $target = $this->device('SN-NEW');

        $this->agent('1234');
        $this->template($source, '1234');

        $result = app(DeviceMigrationService::class)->migrate($source, $target);

        $this->assertFalse($result['failed']);
        $this->assertSame(1, $result['employees']);
        $this->assertSame(1, $result['templates']);
        $this->assertSame(1, $result['source_templates']);

        // The roster went to the target...
        $this->assertSame(
            1,
            $target->commands()->where('type', 'userinfo_upsert')->count()
        );

        // ...and so did the template the source was holding.
        $push = $target->commands()->where('type', 'push_fingertmp')->first();
        $this->assertNotNull($push);
        $this->assertStringContainsString('PIN=1234', $push->data);
        $this->assertStringContainsString('TMP=QUJDREVGRw==', $push->data);
    }

    /**
     * The source terminal is deliberately left alone: clearing it is a
     * destructive action that deserves its own decision, and the old unit is
     * often still standing there during a handover.
     */
    public function test_migration_does_not_touch_the_source_device(): void
    {
        $this->office();
        $source = $this->device('SN-OLD');
        $target = $this->device('SN-NEW');

        $this->template($source, '1234');

        app(DeviceMigrationService::class)->migrate($source, $target);

        $this->assertSame(0, $source->commands()->count());
        $this->assertDatabaseCount('fingerprint_templates', 1);
    }

    public function test_migration_refuses_the_same_device_twice(): void
    {
        $this->office();
        $device = $this->device();

        $result = app(DeviceMigrationService::class)->migrate($device, $device);

        $this->assertTrue($result['failed']);
        $this->assertSame('same_device', $result['reason']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    /**
     * Both the roster query and the template query are scoped by office, so a
     * cross-office migration would silently queue the wrong people. It has to
     * be refused rather than half-applied.
     */
    public function test_migration_refuses_devices_from_different_offices(): void
    {
        $this->office(1, 2);
        $this->office(1, 3, 'Asia/Jakarta');

        $source = $this->device('SN-OLD', 1, 2);
        $target = $this->device('SN-NEW', 1, 3);

        $result = app(DeviceMigrationService::class)->migrate($source, $target);

        $this->assertTrue($result['failed']);
        $this->assertSame('different_office', $result['reason']);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_the_migration_screen_queues_and_redirects(): void
    {
        $this->office();
        $source = $this->device('SN-OLD');
        $target = $this->device('SN-NEW');

        $this->agent('1234');
        $this->template($source, '1234');

        $response = $this->actingUser()->post('/devices/migrate', [
            'source' => $source->id,
            'target' => $target->id,
        ]);

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('success');
        $this->assertGreaterThan(0, $target->commands()->count());
    }

    public function test_the_migration_screen_reports_a_refused_migration(): void
    {
        $this->office();
        $source = $this->device('SN-OLD');
        $target = $this->device('SN-NEW', 1, 3);

        $response = $this->actingUser()->post('/devices/migrate', [
            'source' => $source->id,
            'target' => $target->id,
        ]);

        $response->assertRedirect(route('devices.migrateDevice'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('device_commands', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Screens follow the locale
    |--------------------------------------------------------------------------
    */

    public function test_the_new_screens_render_in_the_active_locale(): void
    {
        $this->office();
        $this->device();

        $en = $this->actingUser()->get('/devices/query-user?lang=en');
        $en->assertOk();
        $en->assertSee('Get User Info');

        $id = $this->actingUser()->get('/devices/query-user?lang=id');
        $id->assertOk();
        $id->assertSee('Ambil Info User');

        $migrate = $this->actingUser()->get('/devices/migrate?lang=es');
        $migrate->assertOk();
        $migrate->assertSee('Migrar dispositivo');

        $remove = $this->actingUser()->get('/devices/remove-fingerprints?lang=id');
        $remove->assertOk();
        $remove->assertSee('Hapus Sidik Jari');
    }

    /**
     * The screens this work edited rather than added: the device list grew
     * buttons and a second confirm-modal action, and the pull form now shares
     * its <select> with the new screens through a partial.
     */
    public function test_the_device_list_and_the_pull_form_still_render(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $index = $this->actingUser()->get('/devices');
        $index->assertOk();
        $index->assertSee('/devices/query-user', false);
        $index->assertSee('/devices/migrate', false);
        $index->assertSee('/devices/remove-fingerprints', false);
        $index->assertSee("/devices/{$device->id}/set-time", false);

        $pull = $this->actingUser()->get('/devices/retrieve/fingerdata');
        $pull->assertOk();
        $pull->assertSee('SN-1', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Remove individual fingers
    |--------------------------------------------------------------------------
    |
    | DATA DELETE FINGERTMP drops one finger and leaves the user record alone,
    | which is the whole difference from DATA DELETE USERINFO. The half of this
    | that is easy to forget is the local side: if our row stays valid, the next
    | push puts the deleted finger straight back.
    |
    */

    public function test_removing_one_finger_queues_the_documented_delete(): void
    {
        $this->office();
        $device = $this->device('SN-1');
        $this->template($device, '1234', 3);

        $result = app(RemoveFingerprintService::class)->run([$device], '1234', [3]);

        $this->assertSame(1, $result['commands']);
        $this->assertSame(1, $result['invalidated']);

        $command = $device->commands()->first();

        $this->assertSame(
            "C:{$command->command}:DATA DELETE FINGERTMP PIN=1234\tFID=3",
            $command->data
        );
        $this->assertSame(AdmsProtocol::TYPE_FINGERTMP_DELETE, $command->type);
        $this->assertSame('PIN:1234/FID:3', $command->reference);
    }

    public function test_removing_a_finger_invalidates_our_stored_template(): void
    {
        $this->office();
        $device = $this->device('SN-1');
        $template = $this->template($device, '1234', 3);

        app(RemoveFingerprintService::class)->run([$device], '1234', [3]);

        $this->assertSame(0, $template->fresh()->valid);
    }

    /**
     * Why invalidating is not optional: PushFingerprintsService distributes
     * valid rows. Leave the row valid and the next push — a migration, a
     * repair, a bulk push — recreates the finger we just deleted, weeks later,
     * with nothing in the UI to explain where it came from.
     */
    public function test_a_removed_finger_is_not_pushed_back_later(): void
    {
        $this->office();
        $source = $this->device('SN-1');
        $target = $this->device('SN-2');
        $this->template($source, '1234', 0);

        // The office holds a distributable template, so a push has work to do.
        $this->assertSame(1, app(PushFingerprintsService::class)->toDevice($target)['commands']);

        app(RemoveFingerprintService::class)->run([$source], '1234', [0]);

        // Clear the earlier push so only the second one is measured, and so the
        // dedupe in PushFingerprintsService cannot mask the result.
        $target->commands()->delete();

        $this->assertSame(0, app(PushFingerprintsService::class)->toDevice($target)['commands']);
    }

    public function test_removing_a_finger_leaves_the_other_terminals_alone(): void
    {
        $this->office();
        $first = $this->device('SN-1');
        $second = $this->device('SN-2');

        $onFirst = $this->template($first, '1234', 0);
        $onSecond = $this->template($second, '1234', 0);

        app(RemoveFingerprintService::class)->run([$first], '1234', [0]);

        $this->assertSame(0, $onFirst->fresh()->valid, 'the terminal we removed from');
        $this->assertSame(1, $onSecond->fresh()->valid, 'the terminal we never touched');
        $this->assertSame(0, $second->commands()->count());
    }

    public function test_removing_all_fingers_queues_one_command_per_index(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $result = app(RemoveFingerprintService::class)->run(
            [$device],
            '1234',
            RemoveFingerprintService::FIDS
        );

        // Ten commands, not one: the protocol has no wildcard.
        $this->assertSame(10, $result['commands']);

        $indices = $device->commands()->orderBy('id')->get()
            ->map(fn ($command) => (int) explode('FID:', $command->reference)[1])
            ->all();

        $this->assertSame(range(0, 9), $indices);
    }

    public function test_removal_is_not_queued_twice_while_pending(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $service = app(RemoveFingerprintService::class);

        $this->assertSame(1, $service->run([$device], '1234', [0])['commands']);
        $this->assertSame(0, $service->run([$device], '1234', [0])['commands']);
        $this->assertSame(1, $device->commands()->count());
    }

    public function test_removal_without_a_pin_or_fingers_queues_nothing(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $service = app(RemoveFingerprintService::class);

        $this->assertSame(0, $service->run([$device], '', [0])['commands']);
        $this->assertSame(0, $service->run([$device], '1234', [])['commands']);
        $this->assertSame(0, $device->commands()->count());
    }

    public function test_the_remove_fingerprints_screen_queues_and_redirects(): void
    {
        $this->office();
        $device = $this->device('SN-1');
        $template = $this->template($device, '1234', 4);

        $response = $this->actingUser()->post('/devices/remove-fingerprints', [
            'device' => $device->id,
            'pin' => '1234',
            'finger' => '4',
        ]);

        $response->assertRedirect(route('devices.removeFingerprints'));
        $response->assertSessionHas('success');

        $this->assertSame(1, $device->commands()->count());
        $this->assertSame(0, $template->fresh()->valid);
    }

    public function test_the_remove_fingerprints_screen_can_target_every_finger(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $this->actingUser()->post('/devices/remove-fingerprints', [
            'device' => $device->id,
            'pin' => '1234',
            'finger' => 'all',
        ])->assertSessionHas('success');

        $this->assertSame(10, $device->commands()->count());
    }

    public function test_the_remove_fingerprints_screen_rejects_a_finger_that_does_not_exist(): void
    {
        $this->office();
        $device = $this->device('SN-1');

        $response = $this->actingUser()->post('/devices/remove-fingerprints', [
            'device' => $device->id,
            'pin' => '1234',
            'finger' => '99',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, $device->commands()->count());
    }
}
