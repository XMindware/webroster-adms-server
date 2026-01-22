<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushChecadaService
{
    protected $endpoint = '/checador/pushChecadaFromADMS';

    /**
     * Constructor
     *
     * Inisialisasi service
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PushChecadaService.php
     */
    public function __construct()
    {
    }

    /**
     * Get Data
     *
     * Mengambil data dari endpoint push kantor pertama (untuk testing/debug)
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PushChecadaService.php
     */
    public function getData()
    {
        $oficina = Oficina::first();
        if (!$oficina) {
            throw new \Exception("No hay oficinas configuradas.");
        }
        $response = Http::get($oficina->public_url() . $this->endpoint);
        return $response->json();
    }

    /**
     * Post Checada Data
     *
     * Mengirim data checada ke endpoint aplikasi utama menggunakan konfigurasi database Oficina
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PushChecadaService.php
     */
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
            Log::info('oficina', ['oficina' => $oficina]);

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
                'public_url' => $oficina->public_url(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Station Agents
     *
     * Mengambil data agen menggunakan endpoint checador
     *
     * @author mdestafadilah
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PushChecadaService.php
     */
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