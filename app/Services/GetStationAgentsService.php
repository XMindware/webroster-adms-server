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
        $context = [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'url' => $oficina->public_url() . '/agentes/getstationagents',
        ];

        Log::debug('getStationAgents: request', $context);

        try {
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

            // Debug: log the raw response so we can diagnose station API shape
            // changes without having to reproduce the issue live.
            Log::debug('getStationAgents: response', array_merge($context, [
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]));

            if ($response->failed()) {
                Log::error('getStationAgents: HTTP request failed', array_merge($context, [
                    'http_status' => $response->status(),
                    'body' => $response->body(),
                ]));

                return (object) [
                    'status' => 'failed',
                    'message' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                ];
            }

            $json = $response->json();

            if (!is_array($json)) {
                Log::error('getStationAgents: unexpected/empty response body', array_merge($context, [
                    'http_status' => $response->status(),
                    'body' => $response->body(),
                ]));

                return (object) [
                    'status' => 'failed',
                    'message' => 'Unexpected or empty response body from station.',
                ];
            }

            // If the station returned a plain list of agents (e.g. [ {...}, {...} ]),
            // keep it as an array so downstream code doesn't have to guess between
            // array/object shapes. If it returned a wrapper object (e.g.
            // { "status": "success", "data": [...] }), keep it as an object so
            // properties like ->status keep working.
            if (array_is_list($json)) {
                Log::debug('getStationAgents: parsed as plain list', array_merge($context, [
                    'count' => count($json),
                ]));

                return $json;
            }

            Log::debug('getStationAgents: parsed as wrapper object', array_merge($context, [
                'keys' => array_keys($json),
            ]));

            return (object) $json;
        } catch (\Exception $e) {
            Log::error('getStationAgents error', array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            return (object) [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }
}
