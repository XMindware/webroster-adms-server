<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetStationAgentsService
{
    /**
     * Constructor
     *
     * Inisialisasi service
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/GetStationAgentsService.php
     */
    public function __construct()
    {
    }

    /**
     * Get Station Agents
     *
     * Mengambil data karyawan (agen) dari server remote untuk kantor tertentu
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/GetStationAgentsService.php
     */
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
        
            return (object)$response->json();
        } catch (\Exception $e) {
            Log::error('getStationAgents error', ['error' => $e->getMessage()]);
            return (object)[
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }
}
