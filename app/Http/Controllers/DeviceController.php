<?php

namespace App\Http\Controllers;

use App\Models\DeviceLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Log;
use Yajra\DataTables\Facades\Datatables;
use App\Services\CommandIdService;
use App\Services\UpdateChecadaService;
use Illuminate\Http\Request;
use App\Models\Agente;
use App\Models\Device;
use App\Models\Oficina;
use App\Models\Attendance;
use App\Models\Command;
use App\Models\FingerLog;
use DB;

class DeviceController extends Controller
{
    // Menampilkan daftar device
    public function index(Request $request)
    {
        $data['title'] = "Biometric Devices";
        $data['log'] = Device::all();
        return view('devices.index',$data);
    }

    public function DeviceLog(Request $request)
    {
        $title = "Devices Log";
        $deviceLogs = DeviceLog::orderBy('id', 'DESC')->paginate(40);
        return view('devices.log', compact('deviceLogs', 'title'));
    }
    
    public function FingerLog(Request $request)
    {
        $title = "Finger Log";
        $deviceLogs = FingerLog::orderBy('id', 'DESC')->paginate(40);
        return view('devices.log', compact('deviceLogs', 'title'));
    }

    public function fingerprints(Request $request){
        $title = "Fingerprints captured";
        $deviceLogs = FingerLog::where('data', 'like', '%FP PIN%')
            ->orderBy('updated_at', 'ASC')
            ->paginate(40)
            ->through(function ($log) {
                preg_match('/FP PIN=(\d+)/', $log->data, $matches);
                $log->idagente = $matches[1] ?? null; // Extracted FP PIN value
                $data = json_decode($log->url);
                $log->employee = Agente::where('idagente', $log->idagente)->first();
                $log->device = Device::where('serial_number', $data->SN)->first();
                return $log;
            });
        return view('devices.fingerprints', compact('deviceLogs','title'));
    }

    public function Attendance(Request $request) {
        $selectedOficina = $request->query('selectedOficina');
        $page = $request->query('page', 1);
    
        $query = Attendance::query();
    
        if ($selectedOficina) {
            $query->whereIn('sn', function ($q) use ($selectedOficina) {
                $q->select('serial_number')
                  ->from('devices')
                  ->where('idoficina', $selectedOficina);
            });
        }
    
        $query->orderBy('updated_at', 'DESC'); // <--- Siempre se ordena por updated_at DESC
    
        if ($request->input('desfasados') === 'on') {
            $filtered = $query->get()->filter(function ($attendance) {
                return $attendance->updated_at->diffInMinutes($attendance->timestamp) > 20;
            });
        
            $filtered = $filtered->sortByDesc('updated_at')->values();
        
            $perPage = 100;
            $currentPageItems = $filtered->slice(($page - 1) * $perPage, $perPage)->values();
        
            $paginator = new LengthAwarePaginator(
                $currentPageItems,
                $filtered->count(),
                $perPage,
                $page,
                [
                    'path' => url()->current(),
                    'query' => request()->query(), // 👈 This appends the current query parameters
                ]
            );
        } else {
            $paginator = $query->paginate(100, ['*'], 'page', $page)
                    ->appends(request()->except('page'));
        }
        
        $oficinas = Oficina::all();
    
        return view('devices.attendance', [
            'attendances' => $paginator,
            'oficinas' => $oficinas,
            'selectedOficina' => $selectedOficina,
            'page' => $page,
        ]);
    }
    

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
        abort(404, 'Device not found');
    }

    // 4. Query DB for logs
    $logs = DeviceLog::select(
        DB::raw("DATE_FORMAT(created_at, '$format') as time_slot"),
        DB::raw("COUNT(*) as count")
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


    public function editAttendance(int $id, Request $request) {
        $attendanceRecord = Attendance::find($id);
        return view('attendance.edit', compact('attendanceRecord'));
    }

    public function fixAttendance(int $id, Request $request) {
        $attendanceRecord = Attendance::find($id);


        $data = [
            'uniqueid' => $attendanceRecord->uniqueid,            
            'timestamp' => $attendanceRecord->timestamp,
            'serial_number' => $attendanceRecord->serial_number,
            'idreloj' => $attendanceRecord->device->idreloj,
            'status1' => $attendanceRecord->status1,
            'status2' => $attendanceRecord->status2,
            'status3' => $attendanceRecord->status3,
            'status4' => $attendanceRecord->status4,
            'status5' => $attendanceRecord->status5,
            'idoficina' => $attendanceRecord->device->oficina->idoficina,
        ];

        // use the UpdateChecadaService to send the data
        $updateChecada = app()->make(UpdateChecadaService::class);

        $response = (object)$updateChecada->postData($data); // Adjust the endpoint as necessary
        // if response is empty json
        if (empty($response)) {
            $this->error("Failed to process record ID {$attendanceRecord->id}. No response from API.");
            return redirect()->route('devices.attendance')->with('error', 'Error al procesar el registro de asistencia');
        }
        if (!$response) {
            $this->error("Failed to process record ID {$attendanceRecord->id}. No response from API.");
            return redirect()->route('devices.attendance')->with('error', 'Error al procesar el registro de asistencia');
        }
        if (property_exists($response, 'status') && $response->status == 'failed') {
            $this->error("Failed to process record ID {$attendanceRecord->id}. " . $response->message);
            return redirect()->route('devices.attendance')->with('error', 'Error al procesar el registro de asistencia');
        }
        return view('attendance.edit', compact('attendanceRecord'));
    }

    public function updateAttendance(Request $request) {
        $attendanceRecord = Attendance::find($request->input('id'));
        $attendanceRecord->timestamp = $request->input('timestamp');
        $attendanceRecord->save();
        return redirect()->route('devices.attendance')->with('success', 'Registro de asistencia actualizado correctamente');
    }

    public function create()
    {
        return view('devices.create');
    }

    public function store(Request $request)
    {
        $device = new Device();
        $device->name = $request->input('name');
        $device->serial_number = $request->input('no_sn');
        $device->idreloj = $request->input('idreloj');
        $device->save();

         return redirect()->route('devices.index')->with('success', 'Biometrico actualizado correctamente');
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
            return redirect()->route('devices.index')->with('error', 'Oficina no encontrada');
        }
        $device->name = $request->input('name');
        $device->serial_number = $request->input('serial_number');
        $device->idreloj = $request->input('idreloj') ?? '999999';
        $device->idoficina = $oficina->idoficina;
        $device->idempresa = $oficina->idempresa;
        $device->save();
      return redirect()->route('devices.index')->with('success', 'Biométrico actualizado correctamente');
    }

    public function restart(Request $request, $id)
    {
        Log::info('Restart', ['id' => $id]);
        $device = Device::find($id);
        try {
            $cmdIdService = resolve(CommandIdService::class); 
            $nextCmdId = $cmdIdService->getNextCmdId();

            $device->commands()->create([
                'device_id' => $device->id,
                'command' => $nextCmdId,
                'data' => "C:{$nextCmdId}:CONTROL DEVICE 03000000",
                'executed_at' => null
            ]);
            return redirect()->route('devices.index')->with('success', 'Biométrico reiniciado correctamente');
        } catch (\Exception $e) {
            return redirect()->route('devices.index')->with('error', 'Error al reiniciar biométrico');
        }
    }

    public function Populate(Request $request, $id)
    {
        Log::info('Populate', ['id' => $id]);
        $device = Device::find($id);
        try {
            $device->populate();
            return redirect()->route('devices.index')->with('success', 'Biométrico actualizado correctamente');
        } catch (\Exception $e) {
            return redirect()->route('devices.index')->with('error', 'Error al actualizar biométrico');
        }
    }
}
