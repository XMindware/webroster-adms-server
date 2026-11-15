<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * S5: actions that change state must not be reachable with GET, so a link
 * or an <img> tag on another page cannot trigger them.
 */
class StateChangingGetTest extends TestCase
{
    use RefreshDatabase;

    private function office(): Oficina
    {
        return Oficina::create([
            'idempresa' => 1, 'idoficina' => 2, 'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test', 'token' => 'token', 'iatacode' => 'CUN',
        ]);
    }

    private function device(): Device
    {
        return Device::create(['serial_number' => 'SN-S5', 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_no_get_route_performs_a_destructive_or_device_action(): void
    {
        $offenders = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (preg_match('#(delete|restart|populate|pull-fingerprints|attendance/fix|logout)#', $route->uri())) {
                $offenders[] = $route->uri();
            }
        }

        $this->assertSame([], $offenders, 'State-changing GET routes: '.implode(', ', $offenders));
    }

    public function test_get_cannot_delete_a_device(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())->get('/devices/delete?id='.$device->id);

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_delete_request_deletes_a_device_and_its_commands(): void
    {
        $device = $this->device();
        $device->commands()->create(['device_id' => $device->id, 'command' => 1, 'data' => 'C:1:INFO']);

        $this->actingAs($this->admin())->delete(route('devices.delete', $device->id))
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertSame(0, Command::where('device_id', $device->id)->count());
    }

    public function test_get_cannot_delete_an_office(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin())->get('/oficinas/delete?id='.$office->id);

        $this->assertDatabaseHas('oficinas', ['id' => $office->id]);
    }

    public function test_delete_request_deletes_an_office(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin())->delete(route('oficinas.delete', $office->id))
            ->assertRedirect(route('devices.oficinas'));

        $this->assertDatabaseMissing('oficinas', ['id' => $office->id]);
    }

    public function test_get_cannot_restart_a_device(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())->get("/devices/{$device->id}/restart");

        $this->assertSame(0, Command::where('device_id', $device->id)->count());
    }

    public function test_post_restart_queues_the_restart_command(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())->post(route('devices.restart', $device->id))
            ->assertRedirect(route('devices.index'));

        $this->assertStringContainsString(
            'CONTROL DEVICE 03000000',
            Command::where('device_id', $device->id)->firstOrFail()->data
        );
    }

    public function test_get_cannot_queue_a_fingerprint_pull(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())->get("/devices/{$device->id}/pull-fingerprints");

        $this->assertSame(0, Command::where('device_id', $device->id)->count());
    }

    public function test_get_cannot_fix_an_attendance_record(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/devices/retrieve/attendance/fix/1')
            ->assertStatus(405);
    }

    public function test_get_does_not_log_the_user_out(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/logout');

        $this->assertAuthenticatedAs($user);
    }

    public function test_post_logout_logs_the_user_out(): void
    {
        $this->actingAs($this->admin())->post(route('logout'))->assertRedirect('login');

        $this->assertGuest();
    }

    public function test_every_web_route_points_at_an_existing_controller_method(): void
    {
        foreach (Route::getRoutes() as $route) {
            // api/ routes are covered by S12's BulkUpsertRouteTest.
            if (str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $action = $route->getAction('uses');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            $this->assertTrue(method_exists($class, $method), "Route {$route->uri()} points at missing {$class}@{$method}");
        }
    }

    public function test_pages_render_actions_as_csrf_protected_forms(): void
    {
        $office = $this->office();
        $device = $this->device();
        $this->actingAs($this->admin());

        $index = $this->get('/devices')->assertOk();
        $index->assertSee('action="'.route('devices.restart', $device->id).'"', false);
        $index->assertSee('action="'.route('devices.populate', $device->id).'"', false);
        $index->assertSee('action="'.route('devices.pullFingerprints', $device->id).'"', false);
        $index->assertSee('name="_token"', false);

        $this->get("/devices/{$device->id}/edit")->assertOk()
            ->assertSee('action="'.route('devices.delete', $device->id).'"', false)
            ->assertSee('name="_method" value="DELETE"', false);

        $this->get('/oficinas')->assertOk()
            ->assertSee('action="'.route('oficinas.delete', $office->id).'"', false);

        $this->get("/oficinas/{$office->id}/edit")->assertOk()
            ->assertSee('action="'.route('oficinas.delete', $office->id).'"', false)
            ->assertSee('action="'.route('logout').'"', false);
    }
}
