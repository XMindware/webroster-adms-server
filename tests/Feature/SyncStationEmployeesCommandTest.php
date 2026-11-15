<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Device;
use App\Models\Oficina;
use App\Services\GetStationAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncStationEmployeesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_queues_commands_for_new_station_employees(): void
    {
        $office = Oficina::create([
            'idempresa' => 10,
            'idoficina' => 20,
            'ubicacion' => 'Station 20',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);

        $device = Device::create([
            'serial_number' => 'SN-001',
            'idempresa' => 10,
            'idoficina' => 20,
            'idreloj' => '1',
        ]);

        Agente::create([
            'idempresa' => 10,
            'idoficina' => 20,
            'idagente' => 1001,
            'shortname' => 'existing',
            'fullname' => 'Existing Employee',
        ]);

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')
            ->once()
            ->withArgs(fn (Oficina $arg) => $arg->is($office))
            ->andReturn([
                [
                    'idagente' => 1001,
                    'shortname' => 'existing',
                    'nombre' => 'Existing',
                    'apellidos' => 'Employee',
                ],
                [
                    'idagente' => 2002,
                    'shortname' => 'new',
                    'nombre' => 'New',
                    'apellidos' => 'Employee',
                ],
            ]);

        $this->artisan('employees:sync-stations')
            ->expectsOutput('Office 10/20: pulled 2, new 1, updated 0, restored 0, removed 0, queued 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('agentes', [
            'idempresa' => 10,
            'idoficina' => 20,
            'idagente' => 2002,
            'fullname' => 'New Employee',
        ]);

        $this->assertDatabaseCount('device_commands', 1);

        $command = $device->commands()->first();

        $this->assertNotNull($command);
        $this->assertStringContainsString('PIN=2002', $command->data);
        $this->assertStringNotContainsString('PIN=1001', $command->data);
    }

    public function test_it_handles_stdclass_wrapper_payload_and_counts_updates(): void
    {
        // Mirrors the real GetStationAgentsService shape: a stdClass object
        // with a "status"/"data" wrapper, instead of a plain array. This was
        // previously silently dropped by normalizeAgents, so no employees
        // (new or existing) were ever synced.
        $office = Oficina::create([
            'idempresa' => 11,
            'idoficina' => 21,
            'ubicacion' => 'Station 21',
            'public_url' => 'https://station21.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);

        Agente::create([
            'idempresa' => 11,
            'idoficina' => 21,
            'idagente' => 3001,
            'shortname' => 'old-shortname',
            'fullname' => 'Old Name',
        ]);

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')
            ->once()
            ->withArgs(fn (Oficina $arg) => $arg->is($office))
            ->andReturn((object) [
                'status' => 'success',
                'data' => [
                    [
                        'idagente' => 3001,
                        'shortname' => 'renamed',
                        'nombre' => 'Renamed',
                        'apellidos' => 'Employee',
                    ],
                ],
            ]);

        $this->artisan('employees:sync-stations')
            ->expectsOutput('Office 11/21: pulled 1, new 0, updated 1, restored 0, removed 0, queued 0')
            ->assertExitCode(0);

        $this->assertDatabaseHas('agentes', [
            'idempresa' => 11,
            'idoficina' => 21,
            'idagente' => 3001,
            'shortname' => 'renamed',
            'fullname' => 'Renamed Employee',
        ]);
    }

    public function test_it_reports_failure_from_station(): void
    {
        $office = Oficina::create([
            'idempresa' => 12,
            'idoficina' => 22,
            'ubicacion' => 'Station 22',
            'public_url' => 'https://station22.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')
            ->once()
            ->withArgs(fn (Oficina $arg) => $arg->is($office))
            ->andReturn((object) [
                'status' => 'failed',
                'message' => 'Connection refused',
            ]);

        $this->artisan('employees:sync-stations')
            ->expectsOutput('Failed office 12/22')
            ->assertExitCode(0);

        $this->assertDatabaseCount('agentes', 0);
    }
}
