<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Terminals POST raw text to /iclock/* with no session and no CSRF token.
 * The framework skips CSRF verification entirely while it believes it is
 * running unit tests, so these tests switch the environment name to put the
 * real check back in the request path.
 */
class DeviceEndpointsCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'local');

        Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
            'timezone' => 'America/Cancun',
        ]);

        Device::create([
            'serial_number' => 'SN-1',
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
        ]);
    }

    private function postRaw(string $uri, string $body)
    {
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function test_csrf_verification_is_really_active_in_these_tests(): void
    {
        $this->post('/login-user', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(419);
    }

    public function test_a_terminal_can_upload_attendance_without_a_csrf_token(): void
    {
        $response = $this->postRaw(
            '/iclock/cdata?SN=SN-1&table=ATTLOG&Stamp=9999',
            "1001\t2026-11-09 08:00:00\t0\t1\t0\t0\t0\r\n1002\t2026-11-09 08:01:30\t1\t15\t0\t0\t0\r\n"
        );

        $response->assertOk();
        $this->assertSame('OK: 2', $response->getContent());
        $this->assertDatabaseHas('attendances', ['sn' => 'SN-1', 'employee_id' => 1001, 'idoficina' => 2]);
        $this->assertDatabaseHas('attendances', ['sn' => 'SN-1', 'employee_id' => 1002, 'idoficina' => 2]);
    }

    public function test_a_terminal_can_acknowledge_a_command_without_a_csrf_token(): void
    {
        $command = Command::create([
            'device_id' => Device::first()->id,
            'command' => 123,
            'type' => 'x',
            'data' => 'C:123:CHECK',
        ]);

        $response = $this->postRaw('/iclock/devicecmd?SN=SN-1', 'ID=123&Return=0&CMD=CHECK');

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertNotNull($command->fresh()->completed_at);
    }

    public function test_terminals_receive_the_same_content_type_as_before_the_framework_upgrade(): void
    {
        $this->get('/iclock/cdata?SN=SN-1&options=all')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->postRaw('/iclock/cdata?SN=SN-1&table=ATTLOG&Stamp=1', "1001\t2026-11-09 08:00:00\t0\t1\r\n")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->get('/iclock/getrequest?SN=SN-1')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
