<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * oficinas.timezone drives two things: the "today" window used by the monitor
 * card, and the "SET OPTIONS DateTime=" command that tells a terminal to move
 * its clock.
 *
 * UTC, GMT and Etc/* are valid IANA identifiers, so they are accepted by
 * DeviceController::normalizeTimezone() and look fine in the table - but no
 * real office is in one. An Indonesian office recorded as UTC made the server
 * order a 7 hour shift, the terminal obeyed, its punches then read as skewed,
 * and the next poll ordered another correction. A loop that never settles.
 */
class OfficeTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function office(string $timezone): Oficina
    {
        return Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Jakarta',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CGK',
            'timezone' => $timezone,
        ]);
    }

    /**
     * The fields oficinas.store writes. public_url, token, iatacode and
     * city_timezone are NOT NULL in the schema, so a partial payload fails on
     * insert rather than saving an incomplete office.
     */
    private function officeInput(string $timezone): array
    {
        return [
            'idempresa' => 1,
            'idoficina' => 9,
            'ubicacion' => 'Jakarta',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CGK',
            'city_timezone' => 'Asia/Jakarta',
            'timezone' => $timezone,
        ];
    }

    private function device(): Device
    {
        return Device::create([
            'serial_number' => 'SN-1',
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
            'name' => 'Test device',
        ]);
    }

    /**
     * A punch whose timestamp is hours away from when the server received it -
     * what a terminal with a genuinely wrong clock produces.
     */
    private function skewedPunch(): void
    {
        DB::table('attendances')->insert([
            'sn' => 'SN-1',
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => 1,
            'timestamp' => now()->subHours(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function clockCorrections(): int
    {
        return Command::where('data', 'like', '%SET OPTIONS DateTime=%')->count();
    }

    /**
     * Pin the clock at an hour where a UTC office's day window really does
     * cover the punch just stored.
     *
     * The correction block is only reached when the counter is above zero. For
     * a UTC office at 04:00 Jakarta the office's UTC day is still the previous
     * calendar day, so the window misses the row, the counter reads zero, and
     * the guard under test is never consulted - the test would pass no matter
     * what timezoneIsGeneric() returned. Freezing the clock keeps that from
     * depending on what time the suite happens to run.
     */
    private function freezeClockAtAGuardedHour(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    }

    public function test_generic_timezones_are_detected(): void
    {
        foreach (['UTC', 'GMT', 'Etc/UTC', 'Etc/GMT+7', '+00:00', '', null] as $timezone) {
            $this->assertTrue(
                Oficina::make(['timezone' => $timezone])->timezoneIsGeneric(),
                var_export($timezone, true) . ' must be treated as generic'
            );
        }
    }

    public function test_real_city_timezones_are_not_generic(): void
    {
        foreach (['Asia/Jakarta', 'America/Cancun', 'Europe/Madrid'] as $timezone) {
            $this->assertFalse(
                Oficina::make(['timezone' => $timezone])->timezoneIsGeneric(),
                $timezone . ' must be treated as a real timezone'
            );
        }
    }

    public function test_clock_correction_is_queued_for_a_real_office_timezone(): void
    {
        $this->freezeClockAtAGuardedHour();

        $this->office('Asia/Jakarta');
        $this->device();
        $this->skewedPunch();

        $this->get('/iclock/getrequest?SN=SN-1')->assertOk();

        $this->assertSame(1, $this->clockCorrections());
    }

    /**
     * The bug this file exists for. Handing a terminal a clock built from a
     * generic zone is worse than leaving its clock alone.
     */
    public function test_clock_correction_is_skipped_for_a_generic_office_timezone(): void
    {
        $this->freezeClockAtAGuardedHour();

        $this->office('UTC');
        $this->device();
        $this->skewedPunch();

        $this->get('/iclock/getrequest?SN=SN-1')->assertOk();

        $this->assertSame(
            0,
            $this->clockCorrections(),
            'a UTC office must not make the server order a 7 hour shift'
        );
    }

    /**
     * The "today" window is built in the office timezone and then compared
     * against created_at, which Eloquent writes in the app timezone. Converting
     * the boundaries to UTC instead compared UTC-formatted strings against
     * local-time rows, sliding the window by the app/office offset and dropping
     * punches that had just arrived.
     *
     * At 04:00 Jakarta a UTC office's calendar day is still the previous one,
     * so the mismatched window excludes the row entirely and the counter - the
     * figure the monitor card shows and the clock correction depends on - reads
     * zero.
     */
    public function test_a_live_punch_is_counted_for_an_office_in_another_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 04:00:00', 'Asia/Jakarta'));

        $this->office('UTC');
        $device = $this->device();
        $this->skewedPunch();

        $this->assertSame(
            1,
            $device->getTimezoneDiscrepancyCount(),
            'a punch stored today must be counted regardless of the office offset'
        );

        $this->assertTrue(
            $device->hasClockDiscrepancyToday(),
            'hasClockDiscrepancyToday() shares the window and must see the same punch'
        );
    }

    public function test_saving_a_generic_timezone_warns_the_operator(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post('/oficinas/store', $this->officeInput('UTC'));

        $response->assertRedirect(route('devices.oficinas'));
        $response->assertSessionHas('warning');
    }

    public function test_saving_a_real_timezone_does_not_warn(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post('/oficinas/store', $this->officeInput('Asia/Jakarta'));

        $response->assertRedirect(route('devices.oficinas'));
        $response->assertSessionMissing('warning');
    }

    public function test_the_office_list_flags_a_generic_timezone(): void
    {
        $this->office('UTC');

        $this->actingAs(User::factory()->create())
            ->get(route('devices.oficinas'))
            ->assertOk()
            ->assertSee(__('oficinas.generic_timezone'), false);
    }

    public function test_the_office_list_does_not_flag_a_real_timezone(): void
    {
        $this->office('Asia/Jakarta');

        $this->actingAs(User::factory()->create())
            ->get(route('devices.oficinas'))
            ->assertOk()
            ->assertDontSee(__('oficinas.generic_timezone'), false);
    }
}
