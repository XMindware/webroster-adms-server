<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushChecadaService
{
    protected $endpoint = '/checador/pushChecadaFromADMS';

    public function __construct()
    {
    }

    public function getData()
    {
        $oficina = Oficina::first();
        if (!$oficina) {
            throw new \Exception("No hay oficinas configuradas.");
        }
        $response = Http::get($oficina->public_url() . $this->endpoint);
        return $response->json();
    }

    public function postData($data): object
    {
        try{            
            // Resolve oficina by idoficina (and idempresa if provided)
            $oficinaQuery = Oficina::where('idoficina', $data['idoficina'] ?? null);
            if (!empty($data['idempresa'])) {
                $oficinaQuery->where('idempresa', $data['idempresa']);
            }
            $oficina = $oficinaQuery->first();
            if (!$oficina) {
                return (object)[
                    'status' => 'failed',
                    'message' => 'Oficina no encontrada para enviar checada'
                ];
            }

            // set headers
            $headers = [
                'Authorization' => $oficina->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $response = Http::withHeaders($headers)
                ->post($oficina->public_url() . $this->endpoint, $data);
            return (object)$response->json();
        } catch (\Exception $e) {
            return (object)[
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    public function getStationAgents(Oficina $oficina)
    {
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
            ->post($oficina->public_url() . '/checador/getStationAgents', $form);
        return $response->json();
    }
}