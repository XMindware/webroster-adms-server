<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * S3: request bodies (biometric templates) and headers must never reach log
 * files, device_log or finger_log. Only metadata is kept.
 */
class PayloadLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** Stand-in for a template: base64-ish and easy to grep for. */
    private const SECRET = 'U0VDUkVUVEVNUExBVEVEQVRBMTIzNDU2Nzg5MA';

    /** @var array<int, string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });

        Oficina::create([
            'idempresa' => 1, 'idoficina' => 2, 'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test', 'token' => 'office-token', 'iatacode' => 'CUN',
            'timezone' => 'America/Cancun',
        ]);
        Device::create(['serial_number' => 'SN-1', 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);
    }

    private function postRaw(string $uri, string $body)
    {
        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_AUTHORIZATION' => 'Bearer header-secret-value',
        ], $body);
    }

    private function assertSecretNowhere(string $secret = self::SECRET): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($secret, $line, 'Payload found in a log message');
        }
        foreach (['device_log', 'finger_log'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString($secret, json_encode($row), "Payload found in {$table}");
            }
        }
    }

    public function test_fingerprint_upload_is_stored_but_not_logged(): void
    {
        $this->postRaw('/iclock/cdata?SN=SN-1&table=OPERLOG', "FP PIN=1234\tFID=0\tSize=4\tValid=1\tTMP=".self::SECRET)
            ->assertOk();

        $this->assertDatabaseHas('fingerprint_templates', ['pin' => '1234']);
        $this->assertSecretNowhere();
        $this->assertNotEmpty($this->logged, 'Expected metadata to still be logged');
    }

    public function test_face_upload_via_querydata_is_not_logged(): void
    {
        $this->postRaw(
            '/iclock/querydata?SN=SN-1&type=tabledata&tablename=biodata',
            "BIODATA Pin=1234\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tTmp=".self::SECRET
        )->assertOk();

        $this->assertSecretNowhere();
    }

    public function test_form_encoded_bodies_are_not_logged(): void
    {
        $this->post('/iclock/cdata?SN=SN-1&table=ATTLOG', ['TMP' => self::SECRET])->assertOk();
        $this->post('/iclock/devicecmd?SN=SN-1', ['ID' => '1', 'Return' => '0', 'CMD' => self::SECRET]);
        $this->post('/iclock/rtdata?SN=SN-1', ['data' => self::SECRET]);

        $this->assertSecretNowhere();
    }

    public function test_raw_command_acks_and_rtdata_bodies_are_not_logged(): void
    {
        $this->postRaw('/iclock/devicecmd?SN=SN-1', 'garbage '.self::SECRET);
        $this->call('GET', '/iclock/rtdata?SN=SN-1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], self::SECRET);

        $this->assertSecretNowhere();
    }

    public function test_commands_carrying_templates_are_sent_but_not_logged(): void
    {
        $device = Device::where('serial_number', 'SN-1')->first();
        $device->commands()->create([
            'device_id' => $device->id,
            'command' => 77,
            'data' => "C:77:DATA UPDATE FINGERTMP PIN=1234\tFID=0\tSize=4\tValid=1\tTMP=".self::SECRET,
        ]);

        $response = $this->get('/iclock/getrequest?SN=SN-1&INFO=Ver%208.0,10,20');

        // The device contract is unchanged: the command still goes out.
        $this->assertStringContainsString(self::SECRET, $response->getContent());
        $this->assertSecretNowhere();
    }

    public function test_headers_are_not_logged(): void
    {
        $this->postRaw('/iclock/cdata?SN=SN-1&table=ATTLOG', "1234\t2026-11-15 10:00:00\t0\t1");
        $this->get('/iclock/cdata?SN=SN-1&options=all&pushver=2.4.1');
        $this->get('/iclock/getrequest?SN=SN-1');

        $this->assertSecretNowhere('header-secret-value');
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('"headers"', $line);
        }
    }

    public function test_scrub_command_removes_payloads_already_stored(): void
    {
        DB::table('finger_log')->insert([
            'url' => json_encode(['SN' => 'SN-1', 'table' => 'OPERLOG', 'TMP' => self::SECRET]),
            'data' => 'FP PIN=1 TMP='.self::SECRET,
        ]);
        DB::table('device_log')->insert([
            'data' => json_encode(['SN' => 'SN-1', 'table' => 'OPERLOG', 'TMP' => self::SECRET]),
            'tgl' => now(), 'sn' => 'SN-1', 'option' => '', 'url' => '/iclock/cdata',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('logs:scrub-payloads', ['--force' => true])->assertSuccessful();

        $this->assertSecretNowhere();
        $row = json_decode(DB::table('device_log')->first()->data, true);
        $this->assertSame('SN-1', $row['SN']);
        $this->assertSame('OPERLOG', $row['table']);
    }
}
