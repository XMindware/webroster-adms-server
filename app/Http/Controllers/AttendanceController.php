<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Oficina;
use App\Services\UpdateChecadaService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * Browsing and repairing attendance punches.
 *
 * Split out of DeviceController, which had grown to hold device operations,
 * office management, attendance browsing and monitoring at once.
 */
class AttendanceController extends Controller
{
    /**
     * Employee ids whose punches are hidden from the attendance list.
     */
    const EXCLUDED_EMPLOYEES = [
        '300101', // Exclude employee 300101 admin
    ];

    public function index(Request $request)
    {
        $selectedOficina = $request->query('selectedOficina');
        $selectedDate = $request->query('selectedDate'); // YYYY-MM-DD
        $idempresa = $request->query('idempresa');
        $page = $request->query('page', 1);

        $query = Attendance::query()->with(['device.oficina']);

        $officeTimezone = $this->officeTimezone($selectedOficina, $idempresa);

        if ($selectedOficina) {
            $query->whereIn('sn', function ($q) use ($selectedOficina, $idempresa) {
                $q->select('serial_number')
                    ->from('devices')
                    ->whereNotIn('employee_id', self::EXCLUDED_EMPLOYEES)
                    ->where('idoficina', $selectedOficina);

                if (!empty($idempresa)) {
                    $q->where('idempresa', $idempresa);
                }
            });
        }

        if ($selectedDate) {
            $this->applyDateFilter($query, $selectedDate, $officeTimezone);
        }

        // Always ordered by updated_at, descending.
        $query->orderBy('updated_at', 'DESC');

        $paginator = $request->input('desfasados') === 'on'
            ? $this->paginateDiscrepancies($query, $officeTimezone, $page)
            : $query->paginate(100, ['*'], 'page', $page)->appends(request()->except('page'));

        $oficinas = Oficina::all();

        return view('devices.attendance', [
            'attendances' => $paginator,
            'oficinas' => $oficinas,
            'selectedOficina' => $selectedOficina,
            'idempresa' => $idempresa,
            'selectedDate' => $selectedDate,
            'page' => $page,
        ]);
    }

    public function edit(int $id, Request $request)
    {
        $attendanceRecord = Attendance::find($id);

        return view('attendance.edit', compact('attendanceRecord'));
    }

    /**
     * Re-send one punch to the upstream attendance API, so a record that never
     * made it across can be pushed again without editing the row by hand.
     */
    public function fix(int $id, Request $request)
    {
        $attendanceRecord = Attendance::find($id);

        if (!$attendanceRecord) {
            return $this->fixResult($request, false, __('devices.attendance_record_not_found'), 404);
        }

        $data = [
            'uniqueid' => $attendanceRecord->response_uniqueid,
            'timestamp' => $attendanceRecord->updated_at->format('Y-m-d H:i:s'),
            'serial_number' => $attendanceRecord->serial_number,
            'idreloj' => $attendanceRecord->device->idreloj,
            'status1' => $attendanceRecord->status1,
            'status2' => $attendanceRecord->status2,
            'status3' => $attendanceRecord->status3,
            'status4' => $attendanceRecord->status4,
            'status5' => $attendanceRecord->status5,
            'idoficina' => $attendanceRecord->device->oficina->idoficina,
        ];

        Log::info('Fixing attendance record', ['data' => $data]);

        $updateChecada = app()->make(UpdateChecadaService::class);
        $response = (object) $updateChecada->postData($data);

        if (empty($response)) {
            Log::error("Failed to process record ID {$attendanceRecord->id}. No response from API.");

            return $this->fixResult($request, false, __('devices.attendance_error_no_response'));
        }

        if (!$response) {
            Log::error("Failed to process record ID {$attendanceRecord->id}. No response from API.");

            return $this->fixResult($request, false, __('devices.attendance_error_processing'));
        }

        if (property_exists($response, 'status') && $response->status == 'failed') {
            Log::error("Failed to process record ID {$attendanceRecord->id}. " . $response->message);

            return $this->fixResult($request, false, __('devices.attendance_error_failed_status', [
                'reason' => $response->message ?? __('devices.attendance_status_failed'),
            ]));
        }

        return $this->fixResult($request, true, __('devices.attendance_fixed_successfully'));
    }

    public function update(Request $request)
    {
        $attendanceRecord = Attendance::find($request->input('id'));
        $attendanceRecord->timestamp = $request->input('timestamp');
        $attendanceRecord->save();

        return redirect()->route('devices.attendance')->with('success', __('devices.attendance_updated_successfully'));
    }

    /**
     * The office timezone that applies to the selected office, if one is set.
     */
    private function officeTimezone(?string $selectedOficina, ?string $idempresa): ?string
    {
        if (!$selectedOficina) {
            return null;
        }

        $oficinaQuery = Oficina::where('idoficina', $selectedOficina);

        if (!empty($idempresa)) {
            $oficinaQuery->where('idempresa', $idempresa);
        }

        return optional($oficinaQuery->first())->timezone;
    }

    /**
     * Restrict the query to one calendar day.
     *
     * With an office timezone the day is converted to UTC boundaries; without
     * one it falls back to comparing the date directly. Note that
     * attendances.timestamp holds the raw string the terminal sent, so the
     * converted boundary is compared against a value that was not normalised
     * to UTC on the way in - see the ingest path in iclockController.
     */
    private function applyDateFilter($query, string $selectedDate, ?string $officeTimezone): void
    {
        if (!$officeTimezone) {
            $query->whereDate('timestamp', $selectedDate);

            return;
        }

        $startOfDayUtc = \Carbon\Carbon::parse($selectedDate, $officeTimezone)->startOfDay()->setTimezone('UTC');
        $endOfDayUtc = \Carbon\Carbon::parse($selectedDate, $officeTimezone)->endOfDay()->setTimezone('UTC');

        $query->whereBetween('timestamp', [$startOfDayUtc, $endOfDayUtc]);
    }

    /**
     * "desfasados" (skewed) filter: punches whose stored timestamp and
     * updated_at disagree by more than 20 minutes, i.e. the terminal's clock
     * was out of step when it was written.
     *
     * The comparison happens in PHP rather than SQL, so the result set is
     * paginated by hand.
     */
    private function paginateDiscrepancies($query, ?string $officeTimezone, int $page): LengthAwarePaginator
    {
        $filtered = $query->get()->filter(function ($attendance) use ($officeTimezone) {
            $tz = $officeTimezone;

            if (!$tz && $attendance->device && $attendance->device->oficina && $attendance->device->oficina->timezone) {
                $tz = $attendance->device->oficina->timezone;
            }

            $updatedAt = $attendance->updated_at;
            $timestamp = $attendance->timestamp;

            if ($tz) {
                $updatedAt = $updatedAt->setTimezone($tz);
                $timestamp = $timestamp->setTimezone($tz);
            }

            return abs($updatedAt->diffInMinutes($timestamp)) > 20;
        });

        $filtered = $filtered->sortByDesc('updated_at')->values();

        $perPage = 100;
        $currentPageItems = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentPageItems,
            $filtered->count(),
            $perPage,
            $page,
            [
                'path' => url()->current(),
                'query' => request()->query(), // keeps the current query string on the pager links
            ]
        );
    }

    /**
     * Answer a fix attempt, as JSON for an AJAX caller and as a view otherwise.
     */
    private function fixResult(Request $request, bool $success, string $message, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => $success, 'message' => $message], $success ? 200 : $status);
        }

        return view('attendance.fix', ['success' => $success, 'message' => $message]);
    }
}
