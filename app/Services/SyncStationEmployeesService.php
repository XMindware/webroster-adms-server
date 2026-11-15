<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncStationEmployeesService
{
    public function __construct(
        protected GetStationAgentsService $stationAgentsService
    ) {
    }

    public function syncOffice(Oficina $oficina): array
    {
        Log::info('SyncStationEmployeesService started', [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
        ]);

        $agents = $this->stationAgentsService->getStationAgents($oficina);

        Log::debug('SyncStationEmployeesService: raw agents payload', [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'type' => is_object($agents) ? get_class($agents) : gettype($agents),
            'payload' => $agents,
        ]);

        if ($this->isFailedResponse($agents)) {
            Log::error('Failed to get station agents', [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
                'error' => $this->extractValue($agents, 'message') ?? 'Unknown error',
            ]);

            return [
                'pulled' => 0,
                'created' => 0,
                'updated' => 0,
                'commands' => 0,
                'failed' => true,
            ];
        }

        $normalizedAgents = $this->normalizeAgents($agents);

        Log::debug('SyncStationEmployeesService: normalized agents', [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'count' => count($normalizedAgents),
        ]);

        if (empty($normalizedAgents)) {
            Log::warning('SyncStationEmployeesService: no agents parsed from station response', [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
                'raw_payload' => $agents,
            ]);
        }

        $newEmployees = collect();
        $updatedEmployees = collect();
        $restoredEmployees = collect();
        $stationIds = [];

        foreach ($normalizedAgents as $agentData) {
            if (!isset($agentData['idagente'], $agentData['shortname'])) {
                Log::warning('SyncStationEmployeesService: skipping malformed agent record', [
                    'idempresa' => $oficina->idempresa,
                    'idoficina' => $oficina->idoficina,
                    'agent_data' => $agentData,
                ]);
                continue;
            }

            $stationIds[] = $agentData['idagente'];

            // Look the agent up including soft-deleted rows so an employee who was
            // previously removed from the station and then re-added is restored in
            // place instead of creating a duplicate row (idagente is not unique).
            $agent = Agente::withTrashed()
                ->where('idempresa', $oficina->idempresa)
                ->where('idoficina', $oficina->idoficina)
                ->firstOrNew(['idagente' => $agentData['idagente']]);

            $existedBefore = $agent->exists;
            $wasTrashed = $agent->trashed();

            $agent->fill([
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
                'idagente' => $agentData['idagente'],
                'shortname' => $agentData['shortname'],
                'fullname' => trim(($agentData['nombre'] ?? '') . ' ' . ($agentData['apellidos'] ?? '')),
            ]);

            if ($wasTrashed) {
                // Back on the station roster: un-mark local + device removal.
                $agent->deleted_at = null;
                $agent->device_removal_queued_at = null;
            }

            $changed = $agent->isDirty();
            $agent->save();

            if (!$existedBefore) {
                Log::debug('SyncStationEmployeesService: new agent created', [
                    'idempresa' => $oficina->idempresa,
                    'idoficina' => $oficina->idoficina,
                    'idagente' => $agent->idagente,
                ]);
                $newEmployees->push($agent);
            } elseif ($wasTrashed) {
                Log::debug('SyncStationEmployeesService: agent restored from removal', [
                    'idempresa' => $oficina->idempresa,
                    'idoficina' => $oficina->idoficina,
                    'idagente' => $agent->idagente,
                ]);
                $restoredEmployees->push($agent);
            } elseif ($changed) {
                Log::debug('SyncStationEmployeesService: existing agent updated', [
                    'idempresa' => $oficina->idempresa,
                    'idoficina' => $oficina->idoficina,
                    'idagente' => $agent->idagente,
                    'changes' => $agent->getChanges(),
                ]);
                $updatedEmployees->push($agent);
            }
        }

        $removedEmployees = $this->markRemovedAgents($oficina, $stationIds);

        $queuedCommands = $this->queueNewEmployeesForDevices($oficina, $newEmployees);

        Log::info('SyncStationEmployeesService completed', [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'pulled' => count($normalizedAgents),
            'created' => $newEmployees->count(),
            'updated' => $updatedEmployees->count(),
            'restored' => $restoredEmployees->count(),
            'removed' => $removedEmployees->count(),
            'commands' => $queuedCommands,
        ]);

        return [
            'pulled' => count($normalizedAgents),
            'created' => $newEmployees->count(),
            'updated' => $updatedEmployees->count(),
            'restored' => $restoredEmployees->count(),
            'removed' => $removedEmployees->count(),
            'commands' => $queuedCommands,
            'failed' => false,
        ];
    }

    /**
     * Mark local agents that no longer appear in the station roster as removed.
     *
     * This is a soft delete only ("marked to remove locally"); the actual
     * DATA DELETE USERINFO command is pushed to devices in a separate, deferred
     * step (see RemoveStationEmployeesService / AgentesController::runPurgeRemoved).
     *
     * Safety guard: if the station returned an empty roster we do NOT treat that
     * as "every employee was removed" — that is almost always a transient station
     * error, and auto-purging an entire office would be destructive.
     */
    protected function markRemovedAgents(Oficina $oficina, array $stationIds): Collection
    {
        if (empty($stationIds)) {
            Log::warning('SyncStationEmployeesService: empty station roster, skipping removal reconciliation', [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
            ]);

            return collect();
        }

        $toRemove = Agente::where('idempresa', $oficina->idempresa)
            ->where('idoficina', $oficina->idoficina)
            ->whereNotIn('idagente', $stationIds)
            ->get();

        foreach ($toRemove as $agent) {
            $agent->delete(); // soft delete -> sets deleted_at

            Log::info('SyncStationEmployeesService: agent marked for removal', [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
                'idagente' => $agent->idagente,
            ]);
        }

        return $toRemove;
    }

    protected function queueNewEmployeesForDevices(Oficina $oficina, Collection $newEmployees): int
    {
        if ($newEmployees->isEmpty()) {
            return 0;
        }

        $devices = Device::where('idempresa', $oficina->idempresa)
            ->where('idoficina', $oficina->idoficina)
            ->get();

        $queuedCommands = 0;

        foreach ($devices as $device) {
            $queuedCommands += $device->populate($newEmployees);
        }

        return $queuedCommands;
    }

    /**
     * Normalize whatever shape the station API returned into a flat array of
     * agent records. Handles: Laravel collections, plain arrays/lists,
     * Traversable objects, stdClass objects, and "wrapper" payloads like
     * { "status": "success", "data": [...] } under a few common key names.
     */
    protected function normalizeAgents($agents): array
    {
        if ($agents instanceof Collection) {
            return $this->normalizeAgents($agents->all());
        }

        if ($agents instanceof \Traversable) {
            return $this->normalizeAgents(iterator_to_array($agents));
        }

        if (is_object($agents)) {
            $agents = get_object_vars($agents);
        }

        if (!is_array($agents)) {
            return [];
        }

        if (empty($agents)) {
            return [];
        }

        // Plain list of agent records, e.g. [ ['idagente' => 1, ...], ... ]
        if (array_is_list($agents)) {
            return $agents;
        }

        // Wrapper payload, e.g. { "status": "success", "data": [...] }
        foreach (['data', 'agentes', 'agents', 'result', 'employees'] as $key) {
            if (isset($agents[$key])) {
                return $this->normalizeAgents($agents[$key]);
            }
        }

        Log::warning('SyncStationEmployeesService: unrecognized agents payload shape', [
            'keys' => array_keys($agents),
        ]);

        return [];
    }

    /**
     * Check whether the station response signals a failure, regardless of
     * whether it arrived as an object or an array.
     */
    protected function isFailedResponse($agents): bool
    {
        return $this->extractValue($agents, 'status') === 'failed';
    }

    /**
     * Read a top-level key off the station response whether it's an object
     * or an array.
     */
    protected function extractValue($agents, string $key)
    {
        if (is_object($agents)) {
            return $agents->{$key} ?? null;
        }

        if (is_array($agents)) {
            return $agents[$key] ?? null;
        }

        return null;
    }
}
