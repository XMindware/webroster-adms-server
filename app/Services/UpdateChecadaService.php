<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateChecadaService
{
    protected $baseUrls;
    
    protected $endpoint = '/checador/updateChecadaADMS';

    /**
     * Constructor
     *
     * Inisialisasi service dan memuat konfigurasi API
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function __construct()
    {
        $this->baseUrls = config('services.apis');
        
        if (!$this->baseUrls) {
            throw new \Exception("API configuration for not found.");
        }
    }

    /**
     * Get Data
     *
     * Mengambil data dari endpoint update menggunakan konfigurasi API pertama
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function getData()
    {

        $response = Http::get($this->baseUrls[0] . $this->endpoint);
        return $response->json();
    }

    /**
     * Post Data
     *
     * Mengirim data update checada ke endpoint API berdasarkan ID kantor dari konfigurasi file
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function postData($data): object
    {
        try{
            
            $currentAPI = (object)$this->baseUrls[$data['idoficina']];
            // set headers
            $headers = [
                'Authorization' => $currentAPI->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $response = Http::withHeaders($headers)
                ->post($currentAPI->base_url . $this->endpoint, $data);
            Log::info("UpdateChecadaService: Response from API", [
                'status' => $response->status(),
                'data' => $response->json()
            ]);
            if ($response->failed()) {
                throw new \Exception("API request failed with status: " . $response->status());
            }
            return (object)$response->json();
        } catch (\Exception $e) {
            return (object)[
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Station Agents
     *
     * Mengambil data agen menggunakan token dari konfigurasi file
     *
     * @author mdestafadilah
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function getStationAgents(Oficina $oficina)
    {
        $currentAPI = (object)$this->baseUrls[$oficina->idoficina];
        $headers = [
            'Authorization' => $currentAPI->token,
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