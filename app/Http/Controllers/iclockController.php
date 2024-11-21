<?php

namespace App\Http\Controllers;
use App\Models\Attendance;
use App\Models\Device;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Log;


class iclockController extends Controller
{

   public function __invoke(Request $request)
   {

   }

    // handshake
    public function handshake(Request $request)
    {
        $data = [
            'url' => json_encode($request->all()),
            'data' => $request->getContent(),
            'sn' => $request->input('SN'),
            'option' => $request->input('option'),
        ];
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
            "Realtime=1\r\n" .
            "Encrypt=0";

        return $r;
    }

    public function receiveRecords(Request $request)
    {   
        
        $content['url'] = json_encode($request->all());
        $content['data'] = $request->getContent();;
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
                'url' => json_encode($request->all()),
                'data' => $request->getContent(),
                'sn' => $request->input('SN'),
                'option' => $request->input('option'),
            ];
            DB::table('device_log')->insert($data);
    
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
                
                DB::table('attendances')->insert($q);
                $tot++;
            }
            return "OK: ".$tot;

        } catch (Throwable $e) {
            $data['error'] = $e;
            DB::table('error_log')->insert($data);
            report($e);
            return "ERROR: ".$tot."\n";
        }
    }
    public function test(Request $request)
    {
        $log['data'] = $request->getContent();
        DB::table('finger_log')->insert($log);
    }
    public function getrequest(Request $request)
    {
        $device = Device::where('serial_number', $request->input('SN'))->first();
        if (!$device) {
            return "ERROR: Device not found";
        }

        $commands = $device->pendingCommands();
   
        if ($commands->count() === 0) {
            return "OK";
        }

        // concatenate all commands
        $response = '';
        foreach ($commands as $command) {
            $response .= $command->data . "\r\n";
            $command->update(['executed_at' => now()]);            
        }
        Log::info('getrequest Response', ['response' => $response]);
        return $response;
    }
    private function validateAndFormatInteger($value)
    {
        return isset($value) && $value !== '' ? (int)$value : null;
        // return is_numeric($value) ? (int) $value : null;
    }

}
