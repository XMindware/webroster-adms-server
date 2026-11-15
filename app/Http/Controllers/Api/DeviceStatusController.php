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
            'online_threshold_minutes' => $this->threshold(),
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

        // Same response for "doesn't exist" and "belongs to another empresa".
        abort_if(!$device, 404, 'Device not found.');

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'online_threshold_minutes' => $this->threshold(),
            'data' => $this->present($request->attributes->get('idempresa'), collect([$device]))->first(),
        ]);
    }

    private function empresaDevices(Request $request)
    {
        return Device::where('idempresa', $request->attributes->get('idempresa'));
    }

    private function threshold(): int
    {
        return (int) config('adms.online_threshold_minutes', 3);
    }

    /**
     * Build the response rows with a fixed number of queries regardless of device count.
     */
    private function present(string $idempresa, Collection $devices): Collection
    {
        if ($devices->isEmpty()) {
            return collect();
        }

        $sns = $devices->pluck('serial_number');

        $latestIds = Attendance::whereIn('sn', $sns)->groupBy('sn')
            ->selectRaw('max(id) as id')->pluck('id');
        $lastChecadas = Attendance::whereIn('id', $latestIds)->get()->keyBy('sn');

        $pending = Command::pending()->whereIn('device_id', $devices->pluck('id'))
            ->groupBy('device_id')->selectRaw('device_id, count(*) as total')
            ->pluck('total', 'device_id');

        $oficinas = Oficina::where('idempresa', $idempresa)
            ->whereIn('idoficina', $devices->pluck('idoficina')->filter()->unique())
            ->get()->keyBy('idoficina');

        $cutoff = now()->subMinutes($this->threshold());

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
                'seconds_since_last_seen' => $lastSeen ? max(0, (int) $lastSeen->diffInSeconds(now())) : null,
                'last_checada' => $checada ? [
                    'employee_id' => $checada->employee_id,
                    // Device clock reading exactly as stored, no timezone conversion.
                    'device_timestamp' => $checada->getRawOriginal('timestamp'),
                    // When this server received the record.
                    'received_at' => $checada->created_at?->toIso8601String(),
                ] : null,
                'pending_commands' => (int) ($pending[$device->id] ?? 0),
            ];
        });
    }
}
