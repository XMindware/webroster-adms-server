<?php

namespace Tests\Feature;

use App\Models\Oficina;
use App\Services\GetStationAgentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GetStationAgentsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeOficina(): Oficina
    {
        return Oficina::create([
            'idempresa' => 30,
            'idoficina' => 40,
            'ubicacion' => 'Station 40',
            'public_url' => 'https://station40.test',
            'token' => 'token',
            'iatacode' => 'CUN',
        ]);
    }

    public function test_it_returns_plain_list_as_array(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response([
                ['idagente' => 1, 'shortname' => 'a', 'nombre' => 'A', 'apellidos' => 'One'],
                ['idagente' => 2, 'shortname' => 'b', 'nombre' => 'B', 'apellidos' => 'Two'],
            ], 200),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function test_it_returns_wrapper_object_with_data_key(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response([
                'status' => 'success',
                'data' => [
                    ['idagente' => 1, 'shortname' => 'a', 'nombre' => 'A', 'apellidos' => 'One'],
                ],
            ], 200),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsObject($result);
        $this->assertEquals('success', $result->status);
        $this->assertCount(1, $result->data);
    }

    public function test_it_flags_http_failure_as_failed_status(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response('Server error', 500),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsObject($result);
        $this->assertEquals('failed', $result->status);
    }

    public function test_it_flags_non_json_body_as_failed_status(): void
    {
        Http::fake([
            'station40.test/agentes/getstationagents' => Http::response('not json', 200),
        ]);

        $result = (new GetStationAgentsService())->getStationAgents($this->makeOficina());

        $this->assertIsObject($result);
        $this->assertEquals('failed', $result->status);
    }
}
