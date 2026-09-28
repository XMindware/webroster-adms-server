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
     * Load the API configuration.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function __construct()
    {
        $this->baseUrls = config('services.apis');

        if (!$this->baseUrls) {
            throw new \Exception(__('devices.api_config_missing'));
        }
    }

    /**
     * Fetch from the update endpoint using the first API configuration.
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
     * Send a punch update to the API endpoint of the office named in $data,
     * resolved against the file configuration.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function postData($data): object
    {
        try {
            $currentAPI = (object) $this->baseUrls[$data['idoficina']];
            $headers = [
                'Authorization' => $currentAPI->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $response = Http::withHeaders($headers)
                ->post($currentAPI->base_url . $this->endpoint, $data);

            Log::info('UpdateChecadaService: Response from API', [
                'status' => $response->status(),
                'data' => $response->json(),
            ]);

            if ($response->failed()) {
                throw new \Exception(__('devices.attendance_api_error', ['status' => $response->status()]));
            }

            return (object) $response->json();
        } catch (\Exception $e) {
            return (object) [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch the agent list using the token from the file configuration.
     *
     * @author mdestafadilah
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/UpdateChecadaService.php
     */
    public function getStationAgents(Oficina $oficina)
    {
        $currentAPI = (object) $this->baseUrls[$oficina->idoficina];
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
