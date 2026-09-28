<?php

namespace App\Services;

use App\Models\Oficina;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushChecadaService
{
    protected $endpoint = '/checador/pushChecadaFromADMS';

    /**
     * Fetch from the first office's push endpoint. Testing/debug only — the
     * real path sends one office's data to that office's own API.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PushChecadaService.php
     */
    public function getData()
    {
        $oficina = Oficina::first();

        if (!$oficina) {
            throw new \Exception(__('oficinas.none_configured'));
        }

        $response = Http::get($oficina->public_url() . $this->endpoint);

        return $response->json();
    }

    /**
     * Send one punch to the main application, using the configuration of the
     * office the punch belongs to.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PushChecadaService.php
     */
    public function postData($data): object
    {
        try {
            // Resolve oficina by idoficina (and idempresa if provided)
            $oficinaQuery = Oficina::where('idoficina', $data['idoficina'] ?? null);

            if (!empty($data['idempresa'])) {
                $oficinaQuery->where('idempresa', $data['idempresa']);
            }

            $oficina = $oficinaQuery->first();

            if (!$oficina) {
                return (object) [
                    'status' => 'failed',
                    'message' => __('oficinas.not_found_for_checkin'),
                ];
            }

            Log::info('oficina', ['oficina' => $oficina]);

            $headers = [
                'Authorization' => $oficina->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $response = Http::withHeaders($headers)
                ->post($oficina->public_url() . $this->endpoint, $data);

            return (object) $response->json();
        } catch (\Exception $e) {
            return (object) [
                'status' => 'failed',
                'public_url' => $oficina->public_url(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch the agent list from the checador endpoint.
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
