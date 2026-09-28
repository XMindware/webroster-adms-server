<?php

namespace App\Http\Controllers;

use App\Models\Agente;
use App\Models\Command;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\FingerLog;
use App\Models\FingerprintTemplate;
use App\Models\Oficina;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use App\Services\DeviceMigrationService;
use App\Services\PullFingerprintsService;
use App\Services\PushFingerprintsService;
use App\Services\RemoveFingerprintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Terminals: registration, the per-device operations that queue ADMS commands,
 * the fingerprint screens, and the monitor/activity views.
 *
 * Office (oficina) management lives in OficinaController and attendance
 * browsing in AttendanceController; both used to be methods here.
 */
class DeviceController extends Controller
{
    /**
     * List all devices.
     */
    public function index(Request $request)
    {
        $data['title'] = __('devices.index_title');
        $data['log'] = Device::all();

        return view('devices.index', $data);
    }

    public function deviceLog(Request $request)
    {
        $title = __('devices.device_log_title');
        $deviceLogs = DeviceLog::orderBy('id', 'DESC')->paginate(40);

        return view('devices.log', compact('deviceLogs', 'title'));
    }

    public function fingerLog(Request $request)
    {
        $title = __('devices.finger_log_title');
        $deviceLogs = FingerLog::orderBy('id', 'DESC')->paginate(40);

        return view('devices.log', compact('deviceLogs', 'title'));
    }

    public function deleteDevice(Request $request)
    {
        $device = Device::find($request->input('id'));

        if (!$device) {
            return redirect()->route('devices.index')->with('error', __('devices.device_not_found'));
        }

        // Drop the commands still queued for this terminal along with it.
        Command::where('device_id', $device->id)->delete();
        $device->delete();

        return redirect()->route('devices.index')->with('success', __('devices.deleted_successfully'));
    }

    /**
     * Templates actually captured from the terminals, one row per employee
     * finger per device.
     *
     * This used to scrape `finger_log` with a LIKE '%FP PIN%' — a raw request
     * log — because there was nowhere else the templates were being kept.
     * They now have a table of their own.
     */
    public function fingerprints(Request $request)
    {
        $title = __('devices.fingerprints_captured');

        $selectedOficina = $request->query('selectedOficina');
        $pin = $request->query('pin');

        $templates = FingerprintTemplate::query()
            ->when($selectedOficina, fn ($q) => $q->where('idoficina', $selectedOficina))
            ->when($pin, fn ($q) => $q->where('pin', $pin))
            ->orderByDesc('captured_at')
            ->orderBy('pin')
            ->orderBy('fid')
            ->paginate(50)
            ->appends($request->except('page'))
            ->through(function (FingerprintTemplate $template) {
                $template->employee = Agente::where('idagente', $template->pin)
                    ->where('idempresa', $template->idempresa)
                    ->where('idoficina', $template->idoficina)
                    ->first();
                $template->deviceRow = Device::where('serial_number', $template->sn)->first();

                return $template;
            });

        $oficinas = Oficina::all();

        return view('devices.fingerprints', compact('templates', 'title', 'oficinas', 'selectedOficina', 'pin'));
    }

    /**
     * Form for pulling fingerprint templates off the terminals.
     */
    public function retrieveFingerData(Request $request)
    {
        $title = __('devices.pull_fingerprints');
        $devices = Device::orderBy('idoficina')->get();
        $oficinas = Oficina::all();

        return view('devices.fingerdata', compact('title', 'devices', 'oficinas'));
    }

    /**
     * Queue the pull. Nothing here waits for templates: the terminal answers
     * on its own polling cycle, minutes later, into /iclock/cdata.
     */
    public function runRetrieveFingerData(Request $request, PullFingerprintsService $service)
    {
        $mode = $request->input('mode', 'bulk');
        $deviceId = $request->input('device');
        $pin = trim((string) $request->input('pin'));

        $device = Device::find($deviceId);

        if (!$device) {
            return $this->deviceNotFound('devices.retrieveFingerData');
        }

        if ($mode === 'pin' && $pin !== '') {
            $queued = $service->forPin($device, $pin);
        } elseif ($mode === 'roster') {
            $queued = $service->forDevice($device);
        } else {
            $queued = $service->bulk($device);
        }

        Log::info('runRetrieveFingerData', [
            'device_id' => $device->id,
            'mode' => $mode,
            'pin' => $pin ?: null,
            'commands' => $queued,
            'triggered_by' => $this->actorIdentifier($request),
        ]);

        if ($queued === 0) {
            return redirect()->route('devices.retrieveFingerData')
                ->with('error', __('devices.pull_nothing_queued'));
        }

        return redirect()->route('devices.retrieveFingerData')
            ->with('success', __('devices.pull_queued', ['count' => $queued, 'device' => $this->deviceLabel($device)]));
    }

    /**
     * One-click bulk pull from the device list.
     */
    public function pullFingerprints(Request $request, $id, PullFingerprintsService $service)
    {
        $device = Device::find($id);

        if (!$device) {
            return $this->deviceNotFound('devices.index');
        }

        $queued = $service->bulk($device);

        return redirect()->route('devices.index')->with(
            $queued > 0 ? 'success' : 'error',
            $queued > 0
                ? __('devices.pull_queued', ['count' => $queued, 'device' => $this->deviceLabel($device)])
                : __('devices.pull_nothing_queued')
        );
    }

    /**
     * Distribute stored templates to a terminal. Manual on purpose — this
     * rewrites biometric data on a live device, so it never runs as a side
     * effect of a roster sync.
     */
    public function pushFingerprints(Request $request, $id, PushFingerprintsService $service)
    {
        $device = Device::find($id);

        if (!$device) {
            return $this->deviceNotFound('devices.index');
        }

        $pins = $request->filled('pins')
            ? array_filter(array_map('trim', explode(',', (string) $request->input('pins'))))
            : null;

        $result = $service->toDevice($device, $pins);

        Log::info('pushFingerprints', array_merge($result, [
            'device_id' => $device->id,
            'triggered_by' => $this->actorIdentifier($request),
        ]));

        return redirect()->back()->with(
            $result['commands'] > 0 ? 'success' : 'error',
            $result['commands'] > 0
                ? __('devices.push_queued', ['count' => $result['commands'], 'device' => $this->deviceLabel($device)])
                : __('devices.push_nothing_queued', ['skipped' => $result['skipped']])
        );
    }

    /**
     * Form for asking one terminal about one employee.
     */
    public function queryUser(Request $request)
    {
        $title = __('devices.get_user_info');
        $devices = Device::orderBy('idoficina')->get();

        return view('devices.user_info', compact('title', 'devices'));
    }

    /**
     * Queue a DATA QUERY USERINFO for one PIN. Nothing waits for an answer: the
     * terminal replies on its own polling cycle, into /iclock/cdata, and the
     * record lands wherever user records normally go.
     */
    public function runQueryUser(Request $request, PullFingerprintsService $service)
    {
        $device = Device::find($request->input('device'));
        $pin = trim((string) $request->input('pin'));

        if (!$device) {
            return $this->deviceNotFound('devices.queryUser');
        }

        if ($pin === '') {
            return redirect()->route('devices.queryUser')->with('error', __('devices.pin_required'));
        }

        $withTemplates = $request->boolean('with_templates');

        $queued = $service->userInfo($device, $pin, $withTemplates);

        Log::info('runQueryUser', [
            'device_id' => $device->id,
            'pin' => $pin,
            'with_templates' => $withTemplates,
            'commands' => $queued,
            'triggered_by' => $this->actorIdentifier($request),
        ]);

        if ($queued === 0) {
            return redirect()->route('devices.queryUser')->with('error', __('devices.query_nothing_queued'));
        }

        return redirect()->route('devices.queryUser')->with('success', __('devices.query_queued', [
            'pin' => $pin,
            'count' => $queued,
            'device' => $this->deviceLabel($device),
        ]));
    }

    /**
     * Form for moving an office's enrolment onto a replacement terminal.
     */
    public function migrateDevice(Request $request)
    {
        $title = __('devices.migrate_device');
        $devices = Device::orderBy('idoficina')->get();

        return view('devices.migrate', compact('title', 'devices'));
    }

    /**
     * Queue the migration. Both halves are queued rather than executed, so the
     * operator gets a redirect and the target terminal works through the
     * commands on its own schedule.
     */
    public function runMigrateDevice(Request $request, DeviceMigrationService $service)
    {
        $source = Device::find($request->input('source'));
        $target = Device::find($request->input('target'));

        if (!$source || !$target) {
            return $this->deviceNotFound('devices.migrateDevice');
        }

        try {
            $result = $service->migrate($source, $target);
        } catch (\Exception $e) {
            Log::error('runMigrateDevice failed', [
                'source_id' => $source->id,
                'target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('devices.migrateDevice')
                ->with('error', __('devices.migration_failed', ['error' => $e->getMessage()]));
        }

        if ($result['failed']) {
            return redirect()->route('devices.migrateDevice')->with('error', match ($result['reason']) {
                'same_device' => __('devices.migration_same_device'),
                'different_office' => __('devices.migration_different_office'),
                default => __('devices.migration_failed', ['error' => (string) $result['reason']]),
            });
        }

        Log::info('runMigrateDevice', array_merge($result, [
            'source_id' => $source->id,
            'target_id' => $target->id,
            'triggered_by' => $this->actorIdentifier($request),
        ]));

        return redirect()->route('devices.index')->with('success', __('devices.migration_queued', [
            'source' => $this->deviceLabel($source),
            'target' => $this->deviceLabel($target),
            'employees' => $result['employees'],
            'templates' => $result['templates'],
        ]));
    }

    /**
     * Form for removing individual fingers from one terminal.
     *
     * Scoped to a single device on purpose: a template row describes what one
     * terminal holds, so "Budi's right thumb is gone" is only ever true of a
     * particular unit. Dropping the whole person from every terminal in an
     * office is the existing Delete User from Device screen.
     */
    public function removeFingerprints(Request $request)
    {
        $title = __('devices.remove_fingerprints');
        $devices = Device::orderBy('idoficina')->get();

        return view('devices.remove_fingerprints', compact('title', 'devices'));
    }

    /**
     * Queue DATA DELETE FINGERTMP for the chosen finger(s) on the chosen device.
     */
    public function runRemoveFingerprints(Request $request, RemoveFingerprintService $service)
    {
        $device = Device::find($request->input('device'));
        $pin = trim((string) $request->input('pin'));
        $finger = (string) $request->input('finger');

        if (!$device) {
            return $this->deviceNotFound('devices.removeFingerprints');
        }

        if ($pin === '') {
            return redirect()->route('devices.removeFingerprints')->with('error', __('devices.pin_required'));
        }

        // "all" is ten commands, not one: the protocol has no wildcard, so every
        // finger index has to be asked for by name.
        if ($finger === 'all') {
            $fids = RemoveFingerprintService::FIDS;
        } elseif ($finger === '' || !ctype_digit($finger) || !in_array((int) $finger, RemoveFingerprintService::FIDS, true)) {
            return redirect()->route('devices.removeFingerprints')->with('error', __('devices.finger_required'));
        } else {
            $fids = [(int) $finger];
        }

        $result = $service->run([$device], $pin, $fids);

        Log::info('runRemoveFingerprints', array_merge($result, [
            'device_id' => $device->id,
            'pin' => $pin,
            'triggered_by' => $this->actorIdentifier($request),
        ]));

        if ($result['commands'] === 0) {
            return redirect()->route('devices.removeFingerprints')
                ->with('error', __('devices.remove_nothing_queued'));
        }

        return redirect()->route('devices.removeFingerprints')->with('success', __('devices.remove_fingerprints_queued', [
            'count' => $result['commands'],
            'pin' => $pin,
            'device' => $this->deviceLabel($device),
            'invalidated' => $result['invalidated'],
        ]));
    }

    public function create()
    {
        $oficinas = Oficina::all();

        return view('devices.create', compact('oficinas'));
    }

    public function store(Request $request)
    {
        $device = new Device();
        $device->name = $request->input('name');
        $device->serial_number = $request->input('no_sn');
        $device->idreloj = $request->input('idreloj');

        if ($request->filled('idoficina')) {
            $oficina = Oficina::where('idoficina', $request->input('idoficina'))->first();

            if ($oficina) {
                $device->idoficina = $oficina->idoficina;
                $device->idempresa = $request->input('idempresa') ?? $oficina->idempresa;
            }
        }

        $device->save();

        return redirect()->route('devices.index')->with('success', __('devices.created_successfully'));
    }

    public function show($id)
    {
        $device = Device::find($id);

        return view('devices.show', compact('device'));
    }

    public function edit($id)
    {
        $device = Device::find($id);
        $oficinas = Oficina::all();

        return view('devices.edit', compact('device', 'oficinas'));
    }

    public function update(Request $request, $id)
    {
        $device = Device::find($id);
        $oficina = Oficina::where('idoficina', $request->input('idoficina'))->first();

        if (!$oficina) {
            return redirect()->route('devices.index')->with('error', __('oficinas.not_found'));
        }

        $device->name = $request->input('name');
        $device->serial_number = $request->input('serial_number');
        $device->idreloj = $request->input('idreloj') ?? '999999';
        $device->idoficina = $oficina->idoficina;
        $device->idempresa = $request->input('idempresa') ?? $oficina->idempresa;
        $device->save();

        return redirect()->route('devices.index')->with('success', __('devices.updated_successfully'));
    }

    public function restart(Request $request, $id, AdmsCommandService $commands)
    {
        Log::info('Restart', ['id' => $id]);

        $device = Device::find($id);

        if (!$device) {
            return $this->deviceNotFound('devices.index');
        }

        try {
            $commands->queue($device, AdmsProtocol::restartDevice(), AdmsProtocol::TYPE_DEVICE_RESTART);

            return redirect()->route('devices.index')->with('success', __('devices.restart_successfully'));
        } catch (\Exception $e) {
            return redirect()->route('devices.index')->with('error', __('devices.error_restarting'));
        }
    }

    /**
     * Set a terminal's clock now, on demand.
     *
     * The same correction iclockController::getrequest() applies automatically
     * when it notices a discrepancy, except this one is asked for rather than
     * inferred — useful right after a terminal has been powered on or moved,
     * when waiting for the next poll and the cooldown is not what you want.
     *
     * The guard is deliberately the same as the automatic path's: an office
     * with a generic timezone (UTC/GMT/an offset) is refused, because ordering a
     * terminal to set its clock from a zone that is not where the building is
     * makes it obey, after which its punches read as skewed and the next poll
     * orders another correction.
     */
    public function setTime(Request $request, $id)
    {
        Log::info('SetTime', ['id' => $id]);

        $device = Device::find($id);

        if (!$device) {
            return $this->deviceNotFound('devices.index');
        }

        $office = $device->oficina;

        if (!$office || $office->timezoneIsGeneric()) {
            Log::warning('setTime: refused, office has no local timezone', [
                'device_id' => $device->id,
                'idoficina' => $office?->idoficina,
                'timezone' => $office?->timezone,
            ]);

            return redirect()->route('devices.index')->with('error', __('devices.set_time_needs_timezone', [
                'device' => $this->deviceLabel($device),
            ]));
        }

        try {
            $timezone = $office->timezone;
            $at = now($timezone);

            app(AdmsCommandService::class)->queue(
                $device,
                AdmsProtocol::setDateTime(AdmsProtocol::encodeDateTime($at)),
                AdmsProtocol::TYPE_SET_DATETIME
            );

            return redirect()->route('devices.index')->with('success', __('devices.set_time_queued', [
                'device' => $this->deviceLabel($device),
                'time' => $at->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
            ]));
        } catch (\Exception $e) {
            Log::error('SetTime failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);

            return redirect()->route('devices.index')->with('error', __('devices.error_setting_time'));
        }
    }

    /**
     * Push the office's roster of employees to this terminal.
     */
    public function populate(Request $request, $id)
    {
        Log::info('Populate', ['id' => $id]);

        $device = Device::find($id);

        try {
            $device->populate();

            return redirect()->route('devices.index')->with('success', __('devices.updated_successfully'));
        } catch (\Exception $e) {
            return redirect()->route('devices.index')->with('error', __('devices.error_updating'));
        }
    }

    public function deleteEmployeeRecord(Request $request)
    {
        $oficinas = Oficina::all();
        $title = __('devices.delete_employee_title');

        return view('devices.delete_employee', compact('oficinas', 'title'));
    }

    public function runDeleteFingerRecord(Request $request, AdmsCommandService $commands)
    {
        $idagente = $request->input('idagente');
        $idoficina = $request->input('oficina');

        $devices = Device::where('idoficina', $idoficina)->get();

        if ($devices->isEmpty()) {
            return redirect()->back()->with('error', __('devices.no_devices_for_office'));
        }

        try {
            // The same PIN has to come off every terminal in the office.
            $commands->queueMany(
                $devices,
                AdmsProtocol::deleteUserinfo($idagente),
                AdmsProtocol::TYPE_USERINFO_DELETE
            );

            return redirect()->route('devices.index')->with('success', __('devices.delete_command_queued', [
                'pin' => $idagente,
                'count' => $devices->count(),
            ]));
        } catch (\Exception $e) {
            Log::error('Error deleting employee record', ['error' => $e->getMessage()]);

            return redirect()->back()->with('error', __('devices.error_sending_delete_command'));
        }
    }

    /**
     * Activity chart data for one terminal over a selectable time range.
     */
    public function devicesActivity(int $id, Request $request)
    {
        $range = $request->get('range', '1d'); // Default to 1 day

        // 1. Determine the start time and interval
        $now = now();
        switch ($range) {
            case '1h':
                $start = $now->copy()->subHour();
                $interval = 'minute';
                break;
            case '6h':
                $start = $now->copy()->subHours(6);
                $interval = 'minute';
                break;
            case '1d':
                $start = $now->copy()->subDay();
                $interval = 'minute';
                break;
            case '7d':
                $start = $now->copy()->subDays(7);
                $interval = 'hour';
                break;
            case '30d':
                $start = $now->copy()->subDays(30);
                $interval = 'day';
                break;
            case '90d':
                $start = $now->copy()->subDays(90);
                $interval = 'day';
                break;
            default:
                $start = $now->copy()->subDay();
                $interval = 'minute';
        }

        // 2. Get the resolution format for groupBy
        $format = [
            'minute' => '%Y-%m-%d %H:%i:00',
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d 00:00:00',
        ][$interval];

        // 3. Get the serial number
        $serial = Device::where('id', $id)->value('serial_number');

        if (!$serial) {
            abort(404, __('devices.device_not_found'));
        }

        // 4. Query DB for logs
        $logs = DeviceLog::select(
            DB::raw("DATE_FORMAT(created_at, '$format') as time_slot"),
            DB::raw('COUNT(*) as count')
        )
            ->where('sn', $serial)
            ->where('url', 'like', '%cdata%')
            ->where('created_at', '>=', $start)
            ->groupBy('time_slot')
            ->orderBy('time_slot')
            ->pluck('count', 'time_slot');

        // 5. Generate full time slot range
        $fullData = [];
        $cursor = $start->copy();

        while ($cursor < $now) {
            $key = $cursor->format(str_replace(['%Y', '%m', '%d', '%H', '%i'], ['Y', 'm', 'd', 'H', 'i'], $format));
            $fullData[$key] = $logs[$key] ?? 0;

            // Advance cursor correctly
            if ($interval === 'minute') {
                $cursor->addMinute();
            } elseif ($interval === 'hour') {
                $cursor->addHour();
            } elseif ($interval === 'day') {
                $cursor->addDay();
            }
        }

        return view('devices.activity', [
            'data' => $fullData,
            'range' => $range,
            'id' => $id,
        ]);
    }

    /**
     * Fleet overview: last punch, current office time and clock-drift count
     * per terminal.
     */
    public function monitor()
    {
        try {
            $devices = Device::all();

            // One aggregate pass for the whole fleet. Calling
            // getTimezoneDiscrepancyCount() inside the loop below ran a query per
            // terminal on every page load, which is what took /devices down with a
            // memory-exhaustion fatal once the table grew.
            $discrepancyCounts = Device::discrepancyCountsFor($devices);

            foreach ($devices as $device) {
                try {
                    $lastAttendance = $device->getLastAttendance();

                    if ($lastAttendance && $lastAttendance->timestamp) {
                        $officeTimezone = $device->oficina ? $device->oficina->timezone : null;
                        $attendanceTime = $officeTimezone
                            ? $lastAttendance->timestamp->setTimezone($officeTimezone)
                            : $lastAttendance->timestamp;

                        $device->last_attendance_time = $attendanceTime;
                        $device->last_attendance_human = $attendanceTime->format('H:i');
                    } else {
                        $device->last_attendance_time = null;
                        $device->last_attendance_human = 'N/A';
                    }

                    // Current office time and timezone info
                    $device->current_office_time = $device->getCurrentOfficeTime();
                    $device->office_timezone = $device->oficina ? $device->oficina->timezone : null;
                    $device->office_time_display = $device->current_office_time ? $device->current_office_time->format('H:i') : 'N/A';

                    // From the batched pass above, not a fresh query per device.
                    $device->discrepancy_count = $discrepancyCounts[$device->serial_number] ?? 0;
                } catch (\Exception $e) {
                    Log::error("Error processing device {$device->id}: " . $e->getMessage());

                    $device->last_attendance_time = null;
                    $device->last_attendance_human = 'Error';
                    $device->current_office_time = null;
                    $device->office_timezone = null;
                    $device->office_time_display = 'Error';
                    $device->discrepancy_count = 0;
                }
            }

            return view('devices.monitor', compact('devices'));
        } catch (\Exception $e) {
            Log::error('Error in monitor method: ' . $e->getMessage());

            return redirect()->route('devices.index')->with('error', __('devices.error_loading_monitor', ['error' => $e->getMessage()]));
        }
    }

    /**
     * A terminal's display name, falling back to its serial number.
     */
    private function deviceLabel(Device $device): string
    {
        return $device->name ?: $device->serial_number;
    }

    /**
     * Redirect to $route with the standard "device not found" error.
     */
    private function deviceNotFound(string $route): RedirectResponse
    {
        return redirect()->route($route)->with('error', __('devices.device_not_found'));
    }
}
