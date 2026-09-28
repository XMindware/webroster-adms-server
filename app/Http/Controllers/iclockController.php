<?php

namespace App\Http\Controllers;
use App\Jobs\SendWebhookJob;
use App\Models\Attendance;
use App\Models\Command;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\Fingerprint;
use App\Models\LogEntry;
use App\Services\Adms\AdmsProtocol;
use App\Services\BiometricRecordParser;
use App\Services\CommandIdService;
use App\Services\FingerprintIngestService;
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
        Log::info('call handshake ', ['request' => $request->all()]);
        try{
            
            $endpoint = parse_url($request->url(), PHP_URL_PATH);

            // add to device logs
            $data = [
                'url' => $endpoint,
                'data' => json_encode($request->getContent()),
                'sn' => $request->input('SN'),
                'option' => $request->input('option') ?? "handshake ",
            ];
            
            DeviceLog::create($data);
            // update status device
            DB::table('devices')->updateOrInsert(
                ['serial_number' => $request->input('SN')],
                ['online' => now()]
            );

            // get timezone from office
            $device = Device::where('serial_number', $request->input('SN'))->first();
            if (!$device) {
                Log::error('handshake', ['error' => 'Device not found']);
                return "ERROR: Device not found";
            }
            $cityTimezone = $device->oficina ? $device->oficina->timezone : 'America/Mexico_City';
            $timezone = $device->oficina ? $device->oficina->timezone : 'America/Mexico_City';

            // set time() to gmt -6
            $date = Carbon::now($cityTimezone);
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
                //"TransTimes=00:00;14:05\r\n" .
                "TransInterval=4\r\n" .
                // Positions 6/7 (EnrollFP, ChgFP) are what make the terminal
                // upload a fingerprint template as soon as it is enrolled or
                // changed. See config/adms.php.
                "TransFlag=" . config('adms.trans_flag', '1111111000') . "\r\n" .
                "TimeZone=". $timezone . "\r\n" .
                "Realtime=1\r\n" .
                "Encrypt=0";

            return $r;

        } catch (Throwable $e) {
            $data['error'] = $e;
            DB::table('error_log')->insert($data);
            report($e);
            return "ERROR: ".$e."\n";
        }
    }

    /**
     * The terminal reports here after running a queued command:
     *
     *   ID=123&Return=0&CMD=DATA
     *
     * Return=0 means it worked; anything else is an error code. Recording this
     * is what turns "we queued a fingerprint pull" into "the device accepted /
     * rejected the pull", which is otherwise invisible.
     */
    public function deviceCommand(Request $request)
    {
        Log::info('call deviceCommand', ['request' => $request->all()]);
        Log::info('deviceCommand content', ['data' => $request->getContent()]);

        try {
            $body = $request->getContent();

            // Some firmware sends the ack as the body, some as query/form params.
            $acks = BiometricRecordParser::parseCommandAcks($body);

            if ($acks === [] && $request->has('ID')) {
                $acks = BiometricRecordParser::parseCommandAcks(
                    http_build_query($request->all())
                );
            }

            if ($acks === []) {
                Log::info('deviceCommand: no parsable acknowledgement', ['data' => $body]);

                return "OK";
            }

            $device = Device::where('serial_number', $request->input('SN'))->first();

            foreach ($acks as $ack) {
                $query = Command::where('command', $ack['id'])
                    ->when($device !== null, fn ($q) => $q->where('device_id', $device->id))
                    ->orderByDesc('id');

                $command = $query->first();

                if (!$command) {
                    Log::warning('deviceCommand: ack for unknown command', [
                        'sn' => $request->input('SN'),
                        'ack' => $ack,
                    ]);
                    continue;
                }

                $succeeded = $ack['return'] === 0;

                $command->forceFill([
                    'executed_at' => $command->executed_at ?? now(),
                    'completed_at' => $succeeded ? now() : $command->completed_at,
                    'failed_at' => $succeeded ? $command->failed_at : now(),
                    'response' => trim((string) $body),
                ])->save();

                Log::info('deviceCommand: command acknowledged', [
                    'command_id' => $command->id,
                    'cmd' => $command->command,
                    'type' => $command->type,
                    'return' => $ack['return'],
                    'ok' => $succeeded,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('deviceCommand', ['error' => $e->getMessage()]);
        }

        return "OK";
    }

    /**
     * Everything a terminal uploads lands here: attendance punches, operation
     * logs, and — the part that matters for biometrics — user records and
     * fingerprint templates, whether volunteered on enrolment or sent in answer
     * to a DATA QUERY FINGERTMP we queued earlier.
     */
    public function receiveRecords(Request $request)
    {
        Log::info('call receiveRecords', ['request' => $request->all()]);

        $content['url'] = json_encode($request->all());
        $content['data'] = $request->getContent();

        DB::table('finger_log')->insert($content);

        try {
            $sn = $request->input('SN');
            $body = (string) $request->getContent();
            $table = strtoupper((string) $request->input('table'));

            $device = Device::where('serial_number', $sn)->first();

            // Keep last-seen fresh whatever the payload turns out to be.
            try {
                DB::table('devices')->updateOrInsert(
                    ['serial_number' => $sn],
                    ['online' => now()]
                );
            } catch (Throwable $e) {
                Log::error('receiveRecords update device ', ['error' => $e->getMessage()]);
            }

            try {
                DeviceLog::create([
                    'url' => parse_url($request->url(), PHP_URL_PATH),
                    'data' => json_encode($request->all()),
                    'tgl' => now(),
                    'sn' => $sn,
                    'option' => $request->input('option') ?? '',
                    // Previously dereferenced a possibly-missing device and
                    // took the whole request down with it.
                    'idreloj' => $device->idreloj ?? '999999',
                ]);
            } catch (Throwable $e) {
                Log::error('receiveRecords device log ', ['error' => $e->getMessage()]);
            }

            $parser = app(BiometricRecordParser::class);

            if ($table === 'OPERLOG' || $table === 'OPLOG' || $parser->looksLikeBiometricPayload($body)) {
                return $this->receiveBiometricRecords($request, $sn, $table, $body, $device, $parser);
            }

            return $this->receiveAttendanceRecords($request, $sn, $body, $device);
        } catch (Throwable $e) {
            Log::error('receiveRecords', ['error' => $e->getMessage()]);

            return "ERROR: 0\n";
        }
    }

    /**
     * User / biometric upload (table=OPERLOG). Templates go to
     * fingerprint_templates via FingerprintIngestService; anything else in the
     * payload keeps its old home in device_options.
     */
    protected function receiveBiometricRecords(
        Request $request,
        ?string $sn,
        string $table,
        string $body,
        ?Device $device,
        BiometricRecordParser $parser
    ) {
        $result = app(FingerprintIngestService::class)->ingest($sn, $body, $device);

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body) ?: []),
            fn ($line) => $line !== ''
        ));

        if ($table === 'OPERLOG') {
            foreach ($lines as $line) {
                // Templates and user records are already stored; only the
                // operation-log remainder belongs in device_options.
                if ($parser->looksLikeBiometricPayload($line)) {
                    continue;
                }

                $this->storeDeviceOption($sn, $line);
            }
        }

        Log::info('receiveRecords: biometric payload', array_merge($result, [
            'sn' => $sn,
            'table' => $table,
            'lines' => count($lines),
        ]));

        return "OK: " . count($lines);
    }

    /**
     * Attendance punches (ATTLOG and anything not recognised as biometric).
     */
    protected function receiveAttendanceRecords(Request $request, ?string $sn, string $body, ?Device $device)
    {
        $tot = 0;
        $rows = [];

        // Split on line breaks only. The old pattern also split on commas,
        // which silently mangled any field that contained one.
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        $idoficina = $device && $device->oficina ? $device->oficina->idoficina : null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = explode("\t", $line);

            if (count($data) < 2) {
                continue;
            }

            $row = [
                'sn' => $sn,
                'table' => $request->input('table'),
                'stamp' => $request->input('Stamp') ?? date('Y-m-d H:i:s'),
                'employee_id' => $data[0],
                'timestamp' => $data[1] ?? date('Y-m-d H:i:s'),
                'idoficina' => $idoficina,
                'idempresa' => $device->idempresa ?? null,
                'status1' => $this->validateAndFormatInteger($data[2] ?? null),
                'status2' => $this->validateAndFormatInteger($data[3] ?? null),
                'status3' => $this->validateAndFormatInteger($data[4] ?? null),
                'status4' => $this->validateAndFormatInteger($data[5] ?? null),
                'status5' => $this->validateAndFormatInteger($data[6] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('attendances')->insert($row);
            $rows[] = $row;
            $tot++;
        }

        // Forward the batch to the device's webhook, if one is configured.
        $this->dispatchWebhook($device, $rows);

        return "OK: " . $tot;
    }

    /**
     * device_options has no oficina columns — the previous implementation
     * inserted them anyway, so every OPERLOG payload failed on the way in.
     */
    protected function storeDeviceOption(?string $sn, string $line): void
    {
        $data = explode("\t", $line);

        if (count($data) < 2) {
            return;
        }

        try {
            DB::table('device_options')->insert([
                'sn' => $sn,
                'table' => 'OPERLOG',
                'stamp' => null,
                'employee_id' => 0,
                'timestamp' => date('Y-m-d H:i:s'),
                'status1' => $this->validateAndFormatInteger($data[2] ?? null),
                'status2' => $this->validateAndFormatInteger($data[3] ?? null),
                'status3' => $this->validateAndFormatInteger($data[4] ?? null),
                'status4' => $this->validateAndFormatInteger($data[5] ?? null),
                'status5' => $this->validateAndFormatInteger($data[6] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('storeDeviceOption', ['error' => $e->getMessage()]);
        }
    }

    public function rtdata(Request $request)
    {
        // log header and content
        Log::info('rtdata', ['url' => json_encode($request->all())]);
        Log::info('rtdata', ['data' => $request->getContent()]);
        // log SN and type


        $data = [
            'url' => json_encode($request->all()),
            'data' => $request->getContent(),
            'sn' => $request->input('SN'),
            'type' => $request->input('type'),
        ];
        Log::info('rtdata', ['data' => $data]);


        // update status device
        DB::table('devices')->updateOrInsert(
            ['serial_number' => $request->input('SN')],
            ['online' => now()]
        );

        $intDateTime = AdmsProtocol::encodeDateTime(Carbon::now('GMT'));

        $response = "DateTime=" . $intDateTime . ",ServerTZ=+0600";

        Log::info('rtdata', ['response' => $response]);

        return "ok";//$response;
    }

    public function querydata(Request $request)
    {
        Log::info('---------call querydata', ['request' => $request->all()]);
        // log header and content
        Log::info('querydata', ['url' => json_encode($request->all())]);
        Log::info('querydata', ['data' => $request->getContent()]);
        $endpoint = parse_url($request->url(), PHP_URL_PATH);

        // add to device logs
        $data = [
            'url' => $endpoint,
            'data' => json_encode($request->all()),
            'sn' => $request->input('SN'),
            'table' => $request->input('table'),
        ];
        Log::info('querydata', ['data' => $data]);

        // update status device
        DB::table('devices')->updateOrInsert(
            ['serial_number' => $request->input('SN')],
            ['online' => now()]
        );

        $response = "OK";

        Log::info('querydata', ['response' => $response]);

        return $response;
    }

    public function test(Request $request)
    {
        Log::info('call test', ['request' => $request->all()]);

        return "OK";
    }
    public function getrequest(Request $request)
    {
        Log::info('call getrequest', ['request' => $request->all()]);

        try {
            $device = Device::where('serial_number', $request->input('SN'))->first();
            if (!$device) {
                Log::error('getrequest', ['error' => 'Device not found']);
                return "ERROR: Device not found";
            }

            $endpoint = parse_url($request->url(), PHP_URL_PATH);

            // add to device logs — a logging failure must never stop the
            // terminal from receiving its queued commands.
            try {
                $data = [
                    'url' => $endpoint,
                    'data' => json_encode($request->all()),
                    'tgl' => now(),
                    'sn' => $request->input('SN'),
                    'option' => $request->input('option') ?? '',
                    'idreloj' => $device->idreloj ?? '999999',
                ];
                DeviceLog::create($data);
                Log::debug("inserted data ", $data);
            } catch (Throwable $e) {
                Log::error('getrequest device log', ['error' => $e->getMessage()]);
            }

            //update last online
            $device->update(['online' => now()]);

            // A fingerprint pull can queue hundreds of commands; handing them
            // all to the terminal in one response is a good way to make it
            // choke. Drain the queue in batches instead — it comes back every
            // few seconds anyway.
            $batchSize = (int) config('adms.commands_per_request', 20);
            $commands = $device->pendingCommands();

            if ($batchSize > 0 && $commands->count() > $batchSize) {
                $commands = $commands->take($batchSize);
            }

            $cmdIdService = resolve(CommandIdService::class);
            $nextCmdId = $cmdIdService->getNextCmdId();
            Log::info('Get Request', ['nextCmdId' => $nextCmdId]);
            
            $intDateTime = AdmsProtocol::encodeDateTime(Carbon::now('America/Mexico_City'));
            /*
            // Add a set time command to the database
            $device->commands()->create([
                'device_id' => $device->id,
                'command' => $nextCmdId,
                'data' => "C:{$nextCmdId}:SET OPTIONS DateTime=" . $intDateTime,
                'executed_at' => null
            ]);*/

            Log::info('getrequest commands', ['commands' => count($commands)]);

            if ($commands->isEmpty()) {
                Log::info('getrequest', ['info' => 'No pending commands']);
                return "OK";
            }

            // Collect and concatenate all command data
            $data = $commands->pluck('data');
            $response = implode("\r\n", $data->toArray()) . "\r\n";

            //remove last \r\n
            $response = substr($response, 0, -2);

            // Update commands' executed_at timestamps
            DB::transaction(function () use ($commands) {
                foreach ($commands as $command) {
                    if ($command instanceof \App\Models\Command) { 
                        $command->update(['executed_at' => now()]);
                    }
                }
            });
            return $response;

        } catch (Throwable $e) {
            $data['data'] = $e;
            Log::error('getrequest', ['data' => $data]);
            report($e);
            return "OK";
        }
    }

    public function quickStatus(Request $request)
    {
        Log::info('call quickStatus', ['request' => $request->all()]);

        $lastError = DB::table('error_log')
            ->orderBy('created_at', 'desc')
            ->first();
        $data = [];

        if ($lastError) {
            Log::info('quickStatus', ['lastError' => $lastError]);
            $data = [
                'status' => 'error',
                'message' => substr($lastError->data, 0, 16),
                'error' => substr($lastError->data, 0, 30),
                'timestamp' => $lastError->created_at ? $lastError->created_at->toIso8601String() : '',
            ];
        }
        else {
            Log::info('quickStatus', ['status' => 'ok']);
            $data = [
                'status' => 'ok',
                'message' => 'No errors found',
            ];
        }
        $response = response()->json($data);
        // Forzar que no se use Transfer-Encoding: chunked
        $response->header('Content-Length', strlen($response->getContent()));
        $response->header('Connection', 'close');
    
        return $response;
    }

    public function uploadLog(Request $request)
    {
        Log::info('📥 Received uploadLog', ['inputs' => $request->all()]);

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return response()->json(['error' => 'Invalid log file'], 400);
        }

        $sn = $request->input('sn', 'UNKNOWN');
        $file = $request->file('file');
        $lines = file($file->getRealPath());

        foreach ($lines as $line) {
            $timestamp = now(); // fallback

            // Try to extract timestamp from log line: "2025-05-13 10:32:55 - INFO - ..."
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
                $timestamp = $matches[1];
            }

            LogEntry::create([
                'sn' => $sn,
                'log_time' => $timestamp,
                'message' => trim($line),
            ]);
        }

        return response()->json(['status' => 'OK']);
    }
    private function validateAndFormatInteger($value)
    {
        return isset($value) && $value !== '' ? (int)$value : null;
        // return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Hand one attendance batch to the device's webhook, if it has one.
     *
     * The POST must never delay the reply the terminal is waiting for: that
     * reply carries the record count the terminal uses as its upload
     * watermark, and a terminal whose answer is late re-sends the same batch.
     * Doing the request here used to hold the terminal for up to five seconds.
     *
     * With the "sync" queue connection - what .env.example ships and what
     * production runs - there is no worker to hand the job to, so it is
     * dispatched for after the response has been flushed. The terminal is freed
     * immediately and no supervisor process is needed. Point QUEUE_CONNECTION
     * at a real driver and run a worker, and the same job is picked up in the
     * background instead.
     */
    private function dispatchWebhook($device, array $attLog): void
    {
        if (!$device || empty($attLog)) {
            return;
        }

        $webhook = $device->webhook;

        if (!$webhook || empty($webhook->url)) {
            return;
        }

        // The webhook id travels with the job so the delivery it is about to
        // make can be tied back to its configuration in the history screen.
        $delivery = [$webhook->url, $attLog, $device->serial_number, $webhook->secret, $webhook->id];

        if (config('queue.default') === 'sync') {
            SendWebhookJob::dispatchAfterResponse(...$delivery);

            return;
        }

        SendWebhookJob::dispatch(...$delivery);
    }

}
