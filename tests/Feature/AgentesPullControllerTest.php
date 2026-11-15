<?php

namespace Tests\Feature;

use App\Models\Oficina;
use App\Models\User;
use App\Services\GetStationAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentesPullControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_pull_flashes_counts_for_the_notification_modal(): void
    {
        $user = User::factory()->create();

        $office = Oficina::create([
            'idempresa' => 50,
            'idoficina' => 60,
            'ubicacion' => 'Station 60',
            'public_url' => 'https://station60.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);

        $mock = $this->mock(GetStationAgentsService::class);
        $mock->shouldReceive('getStationAgents')
            ->once()
            ->withArgs(fn (Oficina $arg) => $arg->is($office))
            ->andReturn([
                ['idagente' => 7001, 'shortname' => 'new1', 'nombre' => 'New', 'apellidos' => 'One'],
            ]);

        $response = $this->actingAs($user)->post(route('agentes.runpull'), [
            'oficina' => 60,
            'idempresa' => 50,
        ]);

        $response->assertRedirect(route('agentes.index'));
        $response->assertSessionHas('pull_result', function ($result) {
            return $result['failed'] === false
                && $result['pulled'] === 1
                && $result['created'] === 1
                && $result['updated'] === 0
                && $result['commands'] === 0;
        });

        $this->assertDatabaseHas('agentes', [
            'idempresa' => 50,
            'idoficina' => 60,
            'idagente' => 7001,
        ]);
    }

    public function test_run_pull_flashes_failure_when_office_not_found(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('agentes.runpull'), [
            'oficina' => 999,
            'idempresa' => 999,
        ]);

        $response->assertRedirect(route('agentes.index'));
        $response->assertSessionHas('pull_result', function ($result) {
            return $result['failed'] === true;
        });
    }
}
