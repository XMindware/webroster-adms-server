<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A "desfase" is a punch whose device timestamp is far from the moment the
 * server received it, in either direction. These tests pin the comparison as
 * an absolute number of whole minutes: Carbon 3 (Laravel 11+) made
 * diffInMinutes() signed and fractional, which would otherwise hide every
 * late upload (negative difference) and move each threshold by up to a minute.
 */
class DesfaseDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday in every Mexican timezone, so "today" is never ambiguous.
        Carbon::setTestNow(Carbon::parse('2026-11-09 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function device(bool $withOffice = true): Device
    {
        if ($withOffice) {
            Oficina::create([
                'idempresa' => 1,
                'idoficina' => 2,
                'ubicacion' => 'Cancun',
                'public_url' => 'https://station.test',
                'token' => 'token',
                'iatacode' => 'CUN',
                'timezone' => 'America/Cancun',
            ]);
        }

        return Device::create([
            'serial_number' => 'SN-1',
            'idempresa' => 1,
            'idoficina' => $withOffice ? 2 : null,
            'idreloj' => '1',
        ]);
    }

    /**
     * Store a punch received now whose device timestamp is $offsetSeconds
     * away from now (negative: punched before it was received).
     */
    private function punch(int $offsetSeconds): void
    {
        DB::table('attendances')->insert([
            'sn' => 'SN-1',
            'table' => 'ATTLOG',
            'stamp' => '1',
            'employee_id' => 1001,
            'timestamp' => now()->addSeconds($offsetSeconds)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_punch_uploaded_late_is_a_desfase(): void
    {
        $device = $this->device();
        $this->punch(-25 * 60);

        $this->assertTrue($device->hayDesfasesHoy());
        $this->assertSame(1, $device->getTimezoneDiscrepancyCount());
    }

    public function test_a_punch_from_a_clock_running_ahead_is_a_desfase(): void
    {
        $device = $this->device();
        $this->punch(25 * 60);

        $this->assertTrue($device->hayDesfasesHoy());
        $this->assertSame(1, $device->getTimezoneDiscrepancyCount());
    }

    public function test_the_threshold_counts_whole_minutes(): void
    {
        $device = $this->device();

        // 20 minutes 59 seconds is still "20 minutes", in both directions.
        $this->punch(-(20 * 60 + 59));
        $this->punch(20 * 60 + 59);

        $this->assertFalse($device->hayDesfasesHoy());
        $this->assertSame(0, $device->getTimezoneDiscrepancyCount());

        $this->punch(-21 * 60);

        $this->assertTrue($device->hayDesfasesHoy());
        $this->assertSame(1, $device->getTimezoneDiscrepancyCount());
    }

    public function test_a_device_without_an_office_uses_the_same_rule(): void
    {
        $device = $this->device(withOffice: false);

        $this->punch(-(20 * 60 + 59));
        $this->assertFalse($device->hayDesfasesHoy());

        $this->punch(-25 * 60);
        $this->assertTrue($device->hayDesfasesHoy());
    }

    public function test_the_monitor_command_reports_late_punches_in_whole_minutes(): void
    {
        $this->device();
        $this->punch(-(25 * 60 + 30));
        $this->punch(-(5 * 60 + 59));

        $this->artisan('monitor:desfases')
            ->expectsOutputToContain('Se encontraron 1 checadas con desfase')
            ->expectsOutputToContain('Diferencia: 25 minutos')
            ->assertSuccessful();
    }
}
