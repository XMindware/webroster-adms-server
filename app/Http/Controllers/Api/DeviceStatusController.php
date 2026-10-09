<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only fleet status for WebRoster, authenticated with a per-empresa API
 * key (see AuthenticateEmpresa).
 *
 * This is the counterpart of the /iclock/* side: the terminal tells us it is
 * alive by polling, so "is this device online, and when did it last see a
 * punch" is answered from data we already have, not by asking the terminal.
 */
class DeviceStatusController extends Controller
{
    /**
     * GET /api/empresa/devices?idoficina=&online=
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idoficina' => 'sometimes|integer',
            'online' => 'sometimes|boolean',
        ]);

        $devices = $this->empresaDevices($request)
            ->when(isset($validated['idoficina']), fn ($q) => $q->where('idoficina', $validated['idoficina']))
            ->orderBy('idoficina')->orderBy('id')
            ->get();

        $data = $this->present($request->attributes->get('idempresa'), $devices);

        // Counted before the "online" filter is applied: a caller asking for
        // the offline devices still needs to know how many of the fleet are up.
        $summary = [
            'total' => $data->count(),
            'online' => $data->where('online', true)->count(),
            'offline' => $data->where('online', false)->count(),
        ];

        if (array_key_exists('online', $validated)) {
            $wanted = filter_var($validated['online'], FILTER_VALIDATE_BOOLEAN);
            $data = $data->where('online', $wanted);
        }

        return response()->json([
            'idempresa' => $request->attributes->get('idempresa'),
            'generated_at' => now()->toIso8601String(),
            'online_threshold_minutes' => Device::onlineThresholdMinutes(),
            'summary' => $summary,
            'data' => $data->values(),
        ]);
    }

    /**
     * GET /api/empresa/devices/{serial_number}
     */
    public function show(Request $request, string $serialNumber): JsonResponse
    {
        $device = $this->empresaDevices($request)
            ->where('serial_number', $serialNumber)
            ->first();

        // Same answer for "does not exist" and "belongs to another empresa":
        // a 403 would confirm that the serial number exists somewhere.
        abort_if(!$device, 404, 'Device not found.');

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'online_threshold_minutes' => Device::onlineThresholdMinutes(),
            'data' => $this->present($request->attributes->get('idempresa'), collect([$device]))->first(),
        ]);
    }

    /**
     * Every query starts here: a key may only ever see its own empresa.
     */
    private function empresaDevices(Request $request)
    {
        return Device::where('idempresa', $request->attributes->get('idempresa'));
    }

    /**
     * Build the response rows with a fixed number of queries, however many
     * devices the empresa has. Doing this per device would be four queries
     * each - and WebRoster polls the list on a timer.
     */
    private function present(string $idempresa, Collection $devices): Collection
    {
        if ($devices->isEmpty()) {
            return collect();
        }

        $sns = $devices->pluck('serial_number');

        // Newest attendance per terminal, in two queries: the id of the last
        // punch for each serial number, then the rows themselves. This is the
        // lookup attendances_sn_id_index exists for.
        $latestIds = Attendance::whereIn('sn', $sns)->groupBy('sn')
            ->selectRaw('max(id) as id')->pluck('id');
        $lastChecadas = Attendance::whereIn('id', $latestIds)->get()->keyBy('sn');

        $pending = Command::pending()->whereIn('device_id', $devices->pluck('id'))
            ->groupBy('device_id')->selectRaw('device_id, count(*) as total')
            ->pluck('total', 'device_id');

        $oficinas = Oficina::where('idempresa', $idempresa)
            ->whereIn('idoficina', $devices->pluck('idoficina')->filter()->unique())
            ->get()->keyBy('idoficina');

        $cutoff = now()->subMinutes(Device::onlineThresholdMinutes());

        return $devices->map(function (Device $device) use ($lastChecadas, $pending, $oficinas, $cutoff) {
            $oficina = $oficinas->get($device->idoficina);
            $checada = $lastChecadas->get($device->serial_number);
            $lastSeen = $device->online;

            return [
                'serial_number' => $device->serial_number,
                'name' => $device->name,
                'idreloj' => $device->idreloj,
                'idoficina' => $device->idoficina !== null ? (int) $device->idoficina : null,
                'oficina' => $oficina?->ubicacion,
                'office_timezone' => $oficina?->timezone,
                'modelo' => $device->modelo,
                'online' => $lastSeen !== null && $lastSeen->gte($cutoff),
                'last_seen_at' => $lastSeen?->toIso8601String(),
                // abs(): a terminal whose clock runs ahead reports `online` in
                // the future - the same skew MonitorDesfases exists for - and
                // Carbon 3 (Laravel 12) returns a *signed* float from
                // diffInSeconds(), so those devices would report a negative
                // "seconds since last seen".
                'seconds_since_last_seen' => $lastSeen ? (int) abs($lastSeen->diffInSeconds(now())) : null,
                'last_checada' => $checada ? [
                    'employee_id' => $checada->employee_id,
                    // The terminal's own clock reading, exactly as stored, so
                    // the caller can tell a punch apart from a skewed device.
                    // $checada->timestamp would be cast to datetime and lose it.
                    'device_timestamp' => $checada->getRawOriginal('timestamp'),
                    // When this server received the record.
                    'received_at' => $checada->created_at?->toIso8601String(),
                ] : null,
                'pending_commands' => (int) ($pending[$device->id] ?? 0),
            ];
        });
    }
}
