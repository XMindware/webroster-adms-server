<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The attendance upload path, from the terminal's point of view.
 *
 * A terminal that cannot advance its upload watermark replays its whole log on
 * every cycle, so the same punch arrives over and over. Those replays are the
 * interesting case: they must not grow the table, but they must also not be
 * thrown away unread, because the status columns belong to the terminal and a
 * later replay can carry a value the first upload did not have.
 */
class AttendanceIngestTest extends TestCase
{
    use RefreshDatabase;

    private function office(): Oficina
    {
        return Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Buaran',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'JKT',
            'timezone' => 'Asia/Jakarta',
        ]);
    }

    private function device(string $sn = 'SN-1'): Device
    {
        return Device::create([
            'serial_number' => $sn,
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
        ]);
    }

    /**
     * Post a raw ADMS body the way a terminal does - not form-encoded.
     */
    private function postAttlog(string $body)
    {
        return $this->call(
            'POST',
            '/iclock/cdata?SN=SN-1&table=ATTLOG&Stamp=9999',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $body
        );
    }

    /**
     * The shape this fleet actually sends: PIN, date-time, punch state, verify
     * mode, then the workcode/reserved tail.
     */
    private function punch(string $employee, string $at, string $status1, string $status2 = '1'): string
    {
        return "{$employee}\t{$at}\t{$status1}\t{$status2}\t\t0\t0\t";
    }

    public function test_a_new_punch_keeps_the_status_the_terminal_reported(): void
    {
        $this->office();
        $this->device();

        $this->postAttlog($this->punch('7', '2026-09-27 21:15:25', '1'))->assertSee('OK: 1');

        $row = DB::table('attendances')->first();

        $this->assertSame(1, (int) $row->status1);
        $this->assertSame(1, (int) $row->status2);
        $this->assertSame('7', (string) $row->employee_id);
    }

    /**
     * The bug this guards: the terminal first reported the punch as "masuk"
     * (status1 = 0) and later replayed the same punch as "keluar"
     * (status1 = 1). The replay used to be dropped on sight, so the row kept
     * the first value forever and the correction was lost.
     */
    public function test_a_replayed_punch_with_a_different_status_corrects_the_stored_row(): void
    {
        $this->office();
        $this->device();

        $this->postAttlog($this->punch('7', '2026-09-27 21:15:25', '0'))->assertSee('OK: 1');

        $storedAt = now()->subHour();
        DB::table('attendances')->update(['updated_at' => $storedAt]);

        // The terminal replays the same punch, now carrying status1 = 1.
        $this->postAttlog($this->punch('7', '2026-09-27 21:15:25', '1'))->assertSee('OK: 1');

        $rows = DB::table('attendances')->get();

        $this->assertCount(1, $rows, 'a replay is the same punch, not a second one');
        $this->assertSame(1, (int) $rows[0]->status1, 'the terminal\'s later report must win');
        $this->assertSame(
            $storedAt->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($rows[0]->updated_at)->toDateTimeString(),
            'correcting a status must not touch updated_at - the skew check compares it to the punch time'
        );
    }

    /**
     * The common case: an identical replay still changes nothing at all.
     */
    public function test_an_identical_replay_neither_inserts_nor_corrects(): void
    {
        $this->office();
        $this->device();

        $body = $this->punch('7', '2026-09-27 21:15:25', '1')
            . "\r\n" . $this->punch('5', '2026-09-27 21:15:35', '1');

        $this->postAttlog($body)->assertSee('OK: 2');
        $this->postAttlog($body)->assertSee('OK: 2');

        $this->assertDatabaseCount('attendances', 2);
    }

    /**
     * A line the parser cannot split used to disappear silently while the reply
     * still said "OK: 0", leaving the terminal to replay the batch forever with
     * nothing in the log to explain it.
     */
    public function test_a_line_without_field_separators_is_reported_in_the_log(): void
    {
        $this->office();
        $this->device();

        // The Log facade is deliberately not mocked: RequestLogger middleware
        // logs every /iclock/cdata request through Log::channel(), and a mock
        // would break the request itself. Listening to the events keeps the
        // real logging path intact.
        $warnings = [];
        Log::listen(function ($message) use (&$warnings) {
            $warnings[] = $message->message;
        });

        $this->postAttlog('888 2026-10-01 10:00:00 1 1 0 0')->assertSee('OK: 0');

        $this->assertDatabaseCount('attendances', 0);
        $this->assertContains(
            'receiveRecords: attendance lines without field separators',
            $warnings,
            'a line the parser cannot split must be visible in the log, not swallowed'
        );
    }
}
