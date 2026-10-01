<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebhookJob;
use App\Models\Command;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\LogEntry;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use App\Services\BiometricRecordParser;
use App\Services\FingerprintIngestService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The terminal-facing iClock / ADMS protocol endpoints.
 *
 * Every method here is called by a ZKTeco-compatible device, not by a browser:
 * the handshake, the command queue poll, and the upload of attendance punches
 * and biometric records. Responses are plain text in the shape the firmware
 * expects, so they are built by hand rather than returned as views or JSON.
 */
class iclockController extends Controller
{
    /**
     * Handshake: GET /iclock/cdata?SN=...
     *
     * The terminal asks for its configuration on first contact and after every
     * reboot, and expects a block of "Key=Value" lines back.
     */
    public function handshake(Request $request)
    {
        Log::info('call handshake ', ['request' => $request->all()]);

        try {
            $endpoint = parse_url($request->url(), PHP_URL_PATH);

            // add to device logs
            $data = [
                'url' => $endpoint,
                'data' => json_encode($request->getContent()),
                'sn' => $request->input('SN'),
                'option' => $request->input('option') ?? 'handshake ',
            ];

            DeviceLog::create($data);

            $this->markDeviceOnline($request->input('SN'));

            // The terminal must already be registered. (The updateOrInsert above
            // normally registers it, so this only fires if that write failed.)
            $device = Device::where('serial_number', $request->input('SN'))->first();

            if (!$device) {
                Log::error('handshake', ['error' => 'Device not found']);

                return 'ERROR: Device not found';
            }

            return $this->handshakeOptions((string) $request->input('SN'));
        } catch (Throwable $e) {
            $data['error'] = $e;
            DB::table('error_log')->insert($data);
            report($e);

            return 'ERROR: ' . $e . "\n";
        }
    }

    /**
     * The configuration block handed back to a terminal on handshake.
     *
     * Two fields used to be sent with the wrong type, which is a good way to
     * make a terminal reject the whole options block:
     *
     *   OpStamp  is a unix timestamp, not a formatted date. It used to carry
     *            "Y-m-d H:i:s".
     *   TimeZone is an hour offset ("7"), not an IANA identifier. It used to
     *            carry the office's "Asia/Jakarta". Both the upstream reference
     *            implementation and the working x100c deployment omit the field
     *            entirely, so it is left out here too. The office timezone is
     *            still applied when attendance timestamps are interpreted.
     *
     * TransTimes was commented out; the reference sends it.
     */
    private function handshakeOptions(string $sn): string
    {
        return "GET OPTION FROM: {$sn}\r\n" .
            "Stamp=9999\r\n" .
            "OpStamp=" . time() . "\r\n" .
            "ErrorDelay=60\r\n" .
            "Delay=30\r\n" .
            "ResLogDay=18250\r\n" .
            "ResLogDelCount=10000\r\n" .
            "ResLogCount=50000\r\n" .
            "TransTimes=00:00;14:05\r\n" .
            "TransInterval=4\r\n" .
            // Positions 6/7 (EnrollFP, ChgFP) are what make the terminal
            // upload a fingerprint template as soon as it is enrolled or
            // changed. See config/adms.php.
            "TransFlag=" . config('adms.trans_flag', '1111111000') . "\r\n" .
            "Realtime=1\r\n" .
            "Encrypt=0";
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

                return 'OK';
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

        return 'OK';
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
                $this->markDeviceOnline($sn);
            } catch (Throwable $e) {
                Log::error('receiveRecords update device ', ['error' => $e->getMessage()]);
            }

            try {
                $this->recordDeviceLog($request, $sn, $device);
            } catch (Throwable $e) {
                Log::error('receiveRecords device log ', ['error' => $e->getMessage()]);
            }

            $parser = app(BiometricRecordParser::class);

            if ($table === 'OPERLOG' || $table === 'OPLOG' || $parser->looksLikeBiometricPayload($body)) {
                return $this->receiveBiometricRecords($sn, $table, $body, $device, $parser);
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

        return 'OK: ' . count($lines);
    }

    /**
     * Attendance punches (ATTLOG and anything not recognised as biometric).
     */
    protected function receiveAttendanceRecords(Request $request, ?string $sn, string $body, ?Device $device)
    {
        // Split on line breaks only. The old pattern also split on commas,
        // which silently mangled any field that contained one.
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        $idoficina = $device && $device->oficina ? $device->oficina->idoficina : null;

        $rows = [];
        $seen = [];
        $unparsable = 0;
        $firstUnparsable = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = explode("\t", $line);

            if (count($data) < 2) {
                // A non-empty line with no field separator is either a payload
                // shape we cannot read (some firmware sends spaces where the
                // protocol says TAB) or a truncated upload. It used to vanish
                // without a trace while the reply still said "OK: 0", which
                // left the terminal re-sending the same batch forever with
                // nothing in the log to explain why. Counted rather than logged
                // per line, so a terminal that sends every record this way
                // cannot flood the log.
                $unparsable++;
                $firstUnparsable ??= substr($line, 0, 200);

                continue;
            }

            // A punch is identified by who punched and when. The same pair
            // arriving twice is the same event, so collapse it inside the
            // batch as well as against the table.
            $key = $data[0] . '|' . $data[1];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $rows[] = [
                'sn' => $sn,
                'table' => $request->input('table'),
                'stamp' => $request->input('Stamp') ?? date('Y-m-d H:i:s'),
                'employee_id' => $data[0],
                'timestamp' => $data[1] ?? date('Y-m-d H:i:s'),
                'idoficina' => $idoficina,
                'idempresa' => $device?->idempresa,
                'status1' => $this->validateAndFormatInteger($data[2] ?? null),
                'status2' => $this->validateAndFormatInteger($data[3] ?? null),
                'status3' => $this->validateAndFormatInteger($data[4] ?? null),
                'status4' => $this->validateAndFormatInteger($data[5] ?? null),
                'status5' => $this->validateAndFormatInteger($data[6] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Split the batch against what the table already holds. A terminal that
        // cannot advance its upload watermark re-sends its whole log on every
        // cycle; without this the table grows without bound (10,389 rows for
        // one device in a single day, 830 of them repeats) and every replayed
        // row is then counted as a clock discrepancy by
        // Device::getTimezoneDiscrepancyCount().
        //
        // A replayed punch is not necessarily an identical one, though. The
        // status columns belong to the terminal, and a later replay can carry
        // values the first upload did not have. Dropping every replay on sight
        // is how a punch the terminal reports as "keluar" (status1 = 1) stayed
        // stored as "masuk" (status1 = 0) for good: the first copy won and
        // every later copy was thrown away unread.
        [$insertRows, $corrections, $unchanged] = $this->partitionAgainstStored($sn, $rows);

        foreach (array_chunk($insertRows, 500) as $chunk) {
            DB::table('attendances')->insert($chunk);
        }

        $corrected = $this->applyStatusCorrections($corrections);

        // Forward only genuinely new punches. A correction describes a punch a
        // receiver may already have been told about, and re-sending it would
        // duplicate the event downstream.
        $this->dispatchWebhook($device, $insertRows);

        if ($unparsable > 0) {
            Log::warning('receiveRecords: attendance lines without field separators', [
                'sn' => $sn,
                'table' => $request->input('table'),
                'lines' => $unparsable,
                'sample' => $firstUnparsable,
            ]);
        }

        // Report how many records the terminal sent, not how many we kept. The
        // terminal uses this count to advance its upload watermark, so handing
        // back a smaller number makes it re-send the same batch forever.
        Log::info('receiveRecords: attendance batch', [
            'sn' => $sn,
            'sent' => count($seen),
            'inserted' => count($insertRows),
            'corrected' => $corrected,
            'duplicates' => $unchanged,
            'unparsable' => $unparsable,
        ]);

        return 'OK: ' . count($seen);
    }

    /**
     * Columns a terminal owns. They are the only thing a replay may change: the
     * punch itself (who, when, on which device) is immutable.
     */
    private const STATUS_COLUMNS = ['status1', 'status2', 'status3', 'status4', 'status5'];

    /**
     * Sort a batch into the punches that are new, the ones already stored with
     * the same statuses, and the ones already stored with *different* statuses.
     *
     * One range query per batch instead of one query per row.
     *
     * Returns [$insert, $corrections, $unchangedCount] where $corrections is a
     * list of ['id' => <stored row id>, status1..status5 => <value>].
     *
     * Note: this compares the raw timestamp string the terminal sent against
     * what MySQL hands back. If a terminal ever uses a different wire format for
     * the same instant (for example a compact 20240920100000), the keys will not
     * match and the replay will be inserted as a duplicate - the "duplicates"
     * and "inserted" counts in the log above are what makes that visible.
     */
    protected function partitionAgainstStored(?string $sn, array $rows): array
    {
        if (empty($rows)) {
            return [[], [], 0];
        }

        $timestamps = array_values(array_filter(array_column($rows, 'timestamp')));

        if (empty($timestamps)) {
            return [$rows, [], 0];
        }

        $existing = DB::table('attendances')
            ->where('sn', $sn)
            ->whereBetween('timestamp', [min($timestamps), max($timestamps)])
            ->get(array_merge(['id', 'employee_id', 'timestamp'], self::STATUS_COLUMNS))
            ->keyBy(fn ($row) => $row->employee_id . '|' . $row->timestamp);

        if ($existing->isEmpty()) {
            return [$rows, [], 0];
        }

        $insert = [];
        $corrections = [];
        $unchanged = 0;

        foreach ($rows as $row) {
            $stored = $existing->get($row['employee_id'] . '|' . $row['timestamp']);

            if ($stored === null) {
                $insert[] = $row;

                continue;
            }

            $statuses = [];
            $differs = false;

            foreach (self::STATUS_COLUMNS as $column) {
                // The query builder hands MySQL's integers back as strings, and
                // the incoming row holds int|null, so both sides are normalised
                // before they are compared.
                $incoming = $this->normalizeStatus($row[$column] ?? null);
                $statuses[$column] = $incoming;

                if ($incoming !== $this->normalizeStatus($stored->{$column})) {
                    $differs = true;
                }
            }

            if (!$differs) {
                $unchanged++;

                continue;
            }

            $corrections[] = ['id' => $stored->id] + $statuses;
        }

        return [$insert, $corrections, $unchanged];
    }

    /**
     * Write back the statuses a terminal reported after the row was stored.
     *
     * Deliberately does NOT touch updated_at. That column is what
     * Device::getTimezoneDiscrepancyCount() compares against the punch time to
     * decide whether the terminal's clock is out of step; bumping it here would
     * make every corrected row look like a skewed clock and could trigger a
     * clock correction that moves the terminal's clock for no reason.
     *
     * @return int how many rows were corrected
     */
    protected function applyStatusCorrections(array $corrections): int
    {
        if (empty($corrections)) {
            return 0;
        }

        // Group by identical status values so a whole-log replay that corrects
        // thousands of rows issues a handful of statements instead of one per
        // row.
        $groups = [];

        foreach ($corrections as $correction) {
            $id = $correction['id'];
            unset($correction['id']);

            $key = implode('|', array_map(
                fn ($value) => $value === null ? 'null' : (string) $value,
                $correction
            ));

            $groups[$key]['values'] = $correction;
            $groups[$key]['ids'][] = $id;
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group['ids'], 1000) as $ids) {
                DB::table('attendances')->whereIn('id', $ids)->update($group['values']);
            }
        }

        return count($corrections);
    }

    /**
     * Compare status values as integers, treating "not reported" as null so an
     * empty field and a missing one are the same thing.
     */
    private function normalizeStatus($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
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

    /**
     * Realtime upload (GET /iclock/rtdata).
     *
     * The terminal only needs its last-seen refreshed and a bare "ok" back; the
     * "DateTime=...,ServerTZ=..." reply it can also accept is deliberately not
     * sent here.
     */
    public function rtdata(Request $request)
    {
        Log::info('rtdata', [
            'request' => $request->all(),
            'data' => $request->getContent(),
        ]);

        $this->markDeviceOnline($request->input('SN'));

        return 'ok';
    }

    public function querydata(Request $request)
    {
        Log::info('---------call querydata', ['request' => $request->all()]);
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

        // A terminal always sends SN. Without one, updateOrInsert would try to
        // insert devices.serial_number = NULL — but that column is NOT NULL and
        // unique, so the request died with a 23000 integrity violation. With
        // APP_DEBUG on, that handed a full stack trace to whoever asked.
        $sn = $request->input('SN');

        if (!$sn) {
            Log::warning('querydata called without SN');

            return 'OK';
        }

        $this->markDeviceOnline($sn);

        Log::info('querydata', ['response' => 'OK']);

        return 'OK';
    }

    public function test(Request $request)
    {
        Log::info('call test', ['request' => $request->all()]);

        return 'OK';
    }

    /**
     * The terminal's polling loop: it comes back every few seconds asking
     * whether there is anything to do, and this is where queued commands are
     * handed out.
     */
    public function getrequest(Request $request)
    {
        Log::info('call getrequest', ['request' => $request->all()]);

        try {
            $device = Device::where('serial_number', $request->input('SN'))->first();

            if (!$device) {
                Log::error('getrequest', ['error' => 'Device not found']);

                return 'ERROR: Device not found';
            }

            // add to device logs — a logging failure must never stop the
            // terminal from receiving its queued commands.
            try {
                $this->recordDeviceLog($request, $request->input('SN'), $device);
            } catch (Throwable $e) {
                Log::error('getrequest device log', ['error' => $e->getMessage()]);
            }

            // update last online
            $device->update(['online' => now()]);

            // A fingerprint pull can queue hundreds of commands; handing them
            // all to the terminal in one response is a good way to make it
            // choke. Drain the queue in batches instead — it comes back every
            // few seconds anyway.
            //
            // The limit is passed down to the query. Taking it off the loaded
            // collection afterwards would still have held every pending row in
            // memory, which is the whole thing this is meant to avoid.
            $batchSize = (int) config('adms.commands_per_request', 20);
            $commands = $device->pendingCommands($batchSize > 0 ? $batchSize : null);

            if ($this->queueClockCorrectionIfNeeded($device)) {
                // Re-read only when a correction was actually queued, so it goes
                // out in this same response — with the same batch limit applied.
                $commands = $device->pendingCommands($batchSize > 0 ? $batchSize : null);
            }

            Log::info('getrequest commands', ['commands' => count($commands)]);

            if ($commands->isEmpty()) {
                Log::info('getrequest', ['info' => 'No pending commands']);

                return 'OK';
            }

            $response = implode("\r\n", $commands->pluck('data')->toArray()) . "\r\n";

            // remove last \r\n
            $response = substr($response, 0, -2);

            // Update commands' executed_at timestamps
            DB::transaction(function () use ($commands) {
                foreach ($commands as $command) {
                    if ($command instanceof Command) {
                        $command->update(['executed_at' => now()]);
                    }
                }
            });

            return $response;
        } catch (Throwable $e) {
            Log::error('getrequest', ['error' => $e->getMessage()]);
            report($e);

            return 'OK';
        }
    }

    /**
     * Queue a clock correction when this terminal's punches look skewed.
     *
     * A correction is queued at most once per cooldown window. A
     * pending-command check cannot throttle this: the correction is handed to
     * the terminal and marked executed in the same request, so it is never
     * pending by the time the next poll arrives. Without the cooldown this
     * block added a device_commands row on every poll (~30 s) for as long as
     * the counter stayed above zero.
     *
     * An office with a generic timezone is skipped on purpose: ordering a
     * terminal to set its clock from a zone that is not where the building is
     * makes it obey, after which its punches read as skewed and the next poll
     * orders another correction — a loop that never settles.
     *
     * @return bool whether a correction was queued
     */
    private function queueClockCorrectionIfNeeded(Device $device): bool
    {
        $cooldown = (int) config('adms.clock_correction_cooldown', 30);

        $recentCorrection = $device->commands()
            ->where('data', 'like', '%SET OPTIONS DateTime=%')
            ->where('created_at', '>=', now()->subMinutes($cooldown))
            ->exists();

        $discrepancies = $device->getTimezoneDiscrepancyCount();

        if ($discrepancies <= 0 || $recentCorrection) {
            return false;
        }

        $office = $device->oficina;

        if ($office && !$office->timezoneIsGeneric()) {
            $timezone = $this->resolveTimezone($office->timezone);

            app(AdmsCommandService::class)->queue(
                $device,
                AdmsProtocol::setDateTime(AdmsProtocol::encodeDateTime(Carbon::now($timezone))),
                AdmsProtocol::TYPE_SET_DATETIME
            );

            return true;
        }

        Log::warning('getrequest: clock correction skipped, office has no local timezone', [
            'sn' => $device->serial_number,
            'idoficina' => $office?->idoficina,
            'timezone' => $office?->timezone,
            'discrepancies' => $discrepancies,
        ]);

        return false;
    }

    public function quickStatus(Request $request)
    {
        Log::info('call quickStatus', ['request' => $request->all()]);

        $lastError = DB::table('error_log')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastError) {
            Log::info('quickStatus', ['lastError' => $lastError]);

            $data = [
                'status' => 'error',
                'message' => substr($lastError->data, 0, 16),
                'error' => substr($lastError->data, 0, 30),
                // created_at comes back from the query builder as a plain
                // string, not a Carbon instance.
                'timestamp' => $lastError->created_at
                    ? Carbon::parse($lastError->created_at)->toIso8601String()
                    : '',
            ];
        } else {
            Log::info('quickStatus', ['status' => 'ok']);

            $data = [
                'status' => 'ok',
                'message' => 'No errors found',
            ];
        }

        $response = response()->json($data);
        // Force it not to use Transfer-Encoding: chunked.
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

    /**
     * Refresh the terminal's last-seen timestamp, registering it if this is the
     * first time we have seen its serial number.
     */
    private function markDeviceOnline(?string $sn): void
    {
        DB::table('devices')->updateOrInsert(
            ['serial_number' => $sn],
            ['online' => now()]
        );
    }

    /**
     * Record one incoming request against the device log.
     */
    private function recordDeviceLog(Request $request, ?string $sn, ?Device $device): void
    {
        DeviceLog::create([
            'url' => parse_url($request->url(), PHP_URL_PATH),
            'data' => json_encode($request->all()),
            'tgl' => now(),
            'sn' => $sn,
            'option' => $request->input('option') ?? '',
            'idreloj' => $device->idreloj ?? '999999',
        ]);
    }

    /**
     * Resolve a timezone to something PHP/Carbon will actually accept.
     *
     * Falls back to the application timezone (config/app.php) instead of a
     * hardcoded city, and tolerates a bad value stored in oficinas.timezone:
     * strings such as "UTC+7" are rejected by PHP with
     * Carbon\Exceptions\InvalidTimeZoneException, which would otherwise take
     * the whole handshake down and leave the terminal without its config.
     */
    private function resolveTimezone(?string $candidate): string
    {
        $fallback = config('app.timezone', 'UTC');

        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return $fallback;
        }

        try {
            new \DateTimeZone($candidate);
        } catch (Throwable $e) {
            Log::warning('resolveTimezone: invalid timezone, falling back to app timezone', [
                'candidate' => $candidate,
                'fallback' => $fallback,
            ]);

            return $fallback;
        }

        return $candidate;
    }

    private function validateAndFormatInteger($value)
    {
        return isset($value) && $value !== '' ? (int) $value : null;
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
