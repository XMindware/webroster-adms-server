<?php

namespace App\Http\Controllers;
use App\Models\Attendance;
use App\Models\Command;
use App\Models\Device;
use App\Models\DeviceLog;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use Log;


class iclockController extends Controller
{

   public function __invoke(Request $request)
   {

   }

    // handshake
    public function handshake(Request $request)
    {

        try{
            
            $data = [
                'url' => json_encode($request->all()),
                'data' => $request->getContent(),
                'sn' => $request->input('SN'),
                'option' => $request->input('option'),
            ];
            Log::info('handshake', ['data' => $data]);
            DB::table('device_log')->insert($data);

            // update status device
            DB::table('devices')->updateOrInsert(
                ['serial_number' => $request->input('SN')],
                ['online' => now()]
            );
            // set time() to gmt -6
            $date = Carbon::now('America/Mexico_City');
            $format = 'Y-m-d H:i:s';
            $localTime = $date->format($format);

            $r = "GET OPTION FROM: {$request->input('SN')}\r\n" .
                "Stamp=9999\r\n" .
                "OpStamp=" . $localTime . "\r\n" .
                "ErrorDelay=60\r\n" .
                "Delay=30\r\n" .
                "ResLogDay=18250\r\n" .
                "ResLogDelCount=10000\r\n" .
                "ResLogCount=50000\r\n" .
                "TransTimes=00:00;14:05\r\n" .
                "TransInterval=1\r\n" .
                "TransFlag=1111000000\r\n" .
                "TimeZone=-6\r\n" .
                "Realtime=0\r\n" .
                "Encrypt=0";

            return $r;

        } catch (Throwable $e) {
            $data['error'] = $e;
            DB::table('error_log')->insert($data);
            report($e);
            return "ERROR: ".$e."\n";
        }
    }

    public function deviceCommand(Request $request)
    {
        // save the content of the request into the Log
        $allLog = json_encode($request->all());

        Log::info('deviceCommand', ['allLog' => $allLog]);
    }

    public function receiveRecords(Request $request)
    {      
        $content['url'] = json_encode($request->all());
        $content['data'] = $request->getContent();

        DB::table('finger_log')->insert($content);
        try {
            $arr = preg_split('/\\r\\n|\\r|,|\\n/', $request->getContent());

            $tot = 0;
            //operation log
            if($request->input('table') == "OPERLOG"){
                // $tot = count($arr) - 1;
                foreach ($arr as $rey) {
                    if(isset($rey)){
                        $tot++;
                    }
                }
                return "OK: ".$tot;
            }

            $data = [
                'url' => json_encode($request->url()),
                'data' => json_encode($request->all()),
                'sn' => $request->input('SN'),
                'option' => $request->input('option'),
                'idreloj' => Device::where('serial_number', $request->input('SN'))->first()->idreloj,
            ];
            DeviceLog::create($data);

            // update status device
            DB::table('devices')->updateOrInsert(
                ['serial_number' => $request->input('SN')],
                ['online' => now()]
            );

            //attendance
            foreach ($arr as $rey) {
                if(empty($rey)){
                    continue;
                }

                $data = explode("\t",$rey);
                //dd($data);
                $q['sn'] = $request->input('SN');
                $q['table'] = $request->input('table');
                $q['stamp'] = $request->input('Stamp');
                $q['employee_id'] = $data[0];
                $q['timestamp'] = $data[1];
                $q['status1'] = $this->validateAndFormatInteger($data[2] ?? null);
                $q['status2'] = $this->validateAndFormatInteger($data[3] ?? null);
                $q['status3'] = $this->validateAndFormatInteger($data[4] ?? null);
                $q['status4'] = $this->validateAndFormatInteger($data[5] ?? null);
                $q['status5'] = $this->validateAndFormatInteger($data[6] ?? null);
                $q['created_at'] = now();
                $q['updated_at'] = now();
                Log::info('receiveRecords', ['q' => $q]);
                DB::table('attendances')->insert($q);
                $tot++;
            }
            return "OK: ".$tot;

        } catch (Throwable $e) {
            Log::info('receiveRecords', ['error' => $e]);
/*
            $data['error'] = $e;
            DB::table('error_log')->insert($data);
            report($e);*/
            return "ERROR: ".$tot."\n";
        }
    }
    public function test(Request $request)
    {
        return "OK";
    }
    public function getrequest(Request $request)
    {
        try {
            $device = Device::where('serial_number', $request->input('SN'))->first();
            if (!$device) {
                return "ERROR: Device not found";
            }

            $commands = $device->pendingCommands();
            $lastCommandId = Command::orderBy('id', 'desc')->value('id') ?? 0;
            
            $intDateTime = $this->oldEncodeTime(
                Carbon::now('America/Mexico_City')->year,
                Carbon::now('America/Mexico_City')->month,
                Carbon::now('America/Mexico_City')->day,
                Carbon::now('America/Mexico_City')->hour,
                Carbon::now('America/Mexico_City')->minute,
                Carbon::now('America/Mexico_City')->second
            );
            // Add a set time command to the database
            $timeCommand = $device->commands()->create([
                'device_id' => $device->id,
                'command' => $lastCommandId,
                'data' => "C:{$lastCommandId} SET OPTION DateTime=" . $intDateTime,
                'executed_at' => null
            ]);

            // Add the newly created command to the collection
            $commands->push($timeCommand);

            if ($commands->isEmpty()) {
                return "OK";
            }

            // Collect and concatenate all command data
            $data = $commands->pluck('data');
            $countresponse = $data->count();
            $response = implode("\r\n", $data->toArray()) . "\r\n";

            // Update commands' executed_at timestamps
            DB::transaction(function () use ($commands) {
                foreach ($commands as $command) {
                    if ($command instanceof \App\Models\Command) { // Ensure it's a model instance
                        $command->update(['executed_at' => now()]);
                    }
                }
            });

            Log::info('getrequest Response count', ['response' => $countresponse]);
            return $response;

        } catch (Throwable $e) {
            $data['data'] = $e;
            DB::table('error_log')->insert($data);
            report($e);
            return "ERROR: ".$e."\n";
        }
    }
    private function validateAndFormatInteger($value)
    {
        return isset($value) && $value !== '' ? (int)$value : null;
        // return is_numeric($value) ? (int) $value : null;
    }

    private function oldEncodeTime(int $year, int $month, int $day, int $hour, int $minute, int $second): int
    {
        return (($year - 2000) * 12 * 31 + (($month - 1) * 31) + $day - 1) * (24 * 60 * 60)
            + ($hour * 60 + $minute) * 60 + $second;
    }

}
