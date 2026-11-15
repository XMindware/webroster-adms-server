<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Support\Facades\Log;

/**
 * Deferred second phase of the station reconciliation.
 *
 * The sync (SyncStationEmployeesService) only *marks* agents as removed locally
 * (soft delete). This service is triggered separately to actually push the
 * removals to the office's devices: it queues DATA DELETE USERINFO commands for
 * every agent that is soft-deleted but has not yet had its device removal queued,
 * then stamps device_removal_queued_at so the same delete is never queued twice.
 */
class RemoveStationEmployeesService
{
    public function purgeOffice(Oficina $oficina): array
    {
        Log::info('RemoveStationEmployeesService started', [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
        ]);

        $pending = Agente::pendingDeviceRemoval()
            ->where('idempresa', $oficina->idempresa)
            ->where('idoficina', $oficina->idoficina)
            ->get();

        if ($pending->isEmpty()) {
            Log::info('RemoveStationEmployeesService: nothing pending device removal', [
                'idempresa' => $oficina->idempresa,
                'idoficina' => $oficina->idoficina,
            ]);

            return [
                'employees' => 0,
                'commands' => 0,
                'failed' => false,
            ];
        }

        $devices = Device::where('idempresa', $oficina->idempresa)
            ->where('idoficina', $oficina->idoficina)
            ->get();

        $queuedCommands = 0;
        foreach ($devices as $device) {
            $queuedCommands += $device->depopulate($pending);
        }

        // Mark each pending agent so we don't re-queue the same deletes on the
        // next purge. We stamp even when the office has no devices, otherwise the
        // agents would stay "pending" forever with nowhere to send the command.
        foreach ($pending as $agent) {
            $agent->forceFill(['device_removal_queued_at' => now()])->saveQuietly();
        }

        Log::info('RemoveStationEmployeesService completed', [
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'employees' => $pending->count(),
            'devices' => $devices->count(),
            'commands' => $queuedCommands,
        ]);

        return [
            'employees' => $pending->count(),
            'commands' => $queuedCommands,
            'failed' => false,
        ];
    }
}
