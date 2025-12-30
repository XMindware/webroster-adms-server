<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetStationAgentsService
{
    public function __construct()
    {
    }

    public function getStationAgents(Oficina $oficina)
    {
        Log::info('getStationAgents', ['job' => self::class]);
        try{
            $headers = [
                'Authorization' => $oficina->token,
                'Content-Type' => 'multipart/form-data',
                'Accept' => 'application/json',
            ];
            $form = [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
            ];
            $response = Http::withHeaders($headers)
                ->withBody(json_encode($form), 'application/json')
                ->post($oficina->public_url() . '/agentes/getstationagents');
        
            return $response->json();
        } catch (\Exception $e) {
            Log::error('getStationAgents error', ['error' => $e->getMessage()]);
            return (object)[
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }
}
