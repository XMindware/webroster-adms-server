<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Device;
use App\Models\Oficina;
use App\Services\GetStationAgentsService;
use App\Services\RemoveStationEmployeesService;
use App\Services\SyncStationEmployeesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveStationEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private function office(int $idempresa, int $idoficina): Oficina
    {
        return Oficina::create([
            'idempresa' => $idempresa,
            'idoficina' => $idoficina,
            'ubicacion' => "Station {$idoficina}",
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);
    }

    public function test_it_soft_deletes_agents_missing_from_station(): void
    {
        $office = $this->office(10, 20);

        Agente::create(['idempresa' => 10, 'idoficina' => 20, 'idagente' => 1001, 'shortname' => 'keep', 'fullname' => 'Keep Me']);
        Agente::create(['idempresa' => 10, 'idoficina' => 20, 'idagente' => 1002, 'shortname' => 'drop', 'fullname' => 'Drop Me']);

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')->once()->andReturn([
            ['idagente' => 1001, 'shortname' => 'keep', 'nombre' => 'Keep', 'apellidos' => 'Me'],
        ]);

        $result = app(SyncStationEmployeesService::class)->syncOffice($office);

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['commands']); // no device commands during sync

        // Still present but soft-deleted (marked to remove), not on the active list.
        $this->assertSoftDeleted('agentes', ['idagente' => 1002]);
        $this->assertDatabaseHas('agentes', ['idagente' => 1001, 'deleted_at' => null]);
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_it_does_not_purge_office_when_station_returns_empty(): void
    {
        $office = $this->office(11, 21);

        Agente::create(['idempresa' => 11, 'idoficina' => 21, 'idagente' => 2001, 'shortname' => 'a', 'fullname' => 'Agent A']);

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')->once()->andReturn([]);

        $result = app(SyncStationEmployeesService::class)->syncOffice($office);

        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('agentes', ['idagente' => 2001, 'deleted_at' => null]);
    }

    public function test_it_restores_agent_that_returns_to_station(): void
    {
        $office = $this->office(12, 22);

        $agent = Agente::create(['idempresa' => 12, 'idoficina' => 22, 'idagente' => 3001, 'shortname' => 'back', 'fullname' => 'Come Back']);
        $agent->forceFill(['device_removal_queued_at' => now()])->save();
        $agent->delete(); // previously removed

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')->once()->andReturn([
            ['idagente' => 3001, 'shortname' => 'back', 'nombre' => 'Come', 'apellidos' => 'Back'],
        ]);

        $result = app(SyncStationEmployeesService::class)->syncOffice($office);

        $this->assertSame(1, $result['restored']);
        $this->assertSame(0, $result['created']); // restored in place, not duplicated
        $this->assertDatabaseCount('agentes', 1);
        $this->assertDatabaseHas('agentes', [
            'idagente' => 3001,
            'deleted_at' => null,
            'device_removal_queued_at' => null,
        ]);
    }

    public function test_purge_queues_delete_commands_and_stamps_agents(): void
    {
        $office = $this->office(13, 23);

        $device = Device::create(['serial_number' => 'SN-DEL', 'idempresa' => 13, 'idoficina' => 23, 'idreloj' => '1']);

        $agent = Agente::create(['idempresa' => 13, 'idoficina' => 23, 'idagente' => 4001, 'shortname' => 'gone', 'fullname' => 'All Gone']);
        $agent->delete(); // soft-deleted, device_removal_queued_at still null

        $result = app(RemoveStationEmployeesService::class)->purgeOffice($office);

        $this->assertSame(1, $result['employees']);
        $this->assertSame(1, $result['commands']);

        $command = $device->commands()->first();
        $this->assertNotNull($command);
        $this->assertStringContainsString('DATA DELETE USERINFO PIN=4001', $command->data);

        // Stamped so a second purge is a no-op.
        $this->assertNotNull(Agente::withTrashed()->where('idagente', 4001)->first()->device_removal_queued_at);

        $second = app(RemoveStationEmployeesService::class)->purgeOffice($office);
        $this->assertSame(0, $second['commands']);
        $this->assertDatabaseCount('device_commands', 1);
    }
}
