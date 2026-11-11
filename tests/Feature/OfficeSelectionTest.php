<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Attendance;
use App\Models\Command;
use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selecting an office by oficinas.id instead of the (idoficina, idempresa) pair.
 *
 * `idoficina` is an office number that repeats across empresas, so every filter
 * built on it alone leaked one customer's data into another's screen, and every
 * form had to carry a hidden `idempresa` kept in sync by JavaScript. These tests
 * pin both halves: the dropdown selects one office, and a filter never crosses
 * the empresa boundary even when the office number is the same.
 */
class OfficeSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function office(int $idempresa, int $idoficina, string $ubicacion): Oficina
    {
        return Oficina::create([
            'idempresa' => $idempresa,
            'idoficina' => $idoficina,
            'ubicacion' => $ubicacion,
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
            'timezone' => 'America/Cancun',
        ]);
    }

    private function device(string $sn, Oficina $oficina): Device
    {
        return Device::create([
            'name' => $sn,
            'serial_number' => $sn,
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'idreloj' => '1',
        ]);
    }

    private function employee(Oficina $oficina, int $pin, string $name): Agente
    {
        return Agente::create([
            'idempresa' => $oficina->idempresa,
            'idoficina' => $oficina->idoficina,
            'idagente' => $pin,
            'shortname' => $name,
            'fullname' => $name,
        ]);
    }

    private function punch(Device $device, int $employeeId): Attendance
    {
        $attendance = new Attendance([
            'sn' => $device->serial_number,
            'employee_id' => $employeeId,
            'timestamp' => now(),
        ]);

        // `table` is not fillable on the model, but the column is NOT NULL.
        $attendance->table = 'ATTLOG';
        $attendance->stamp = '999';
        $attendance->save();

        return $attendance;
    }

    /**
     * Two customers, same office number. Picking one office must show only its
     * own employees.
     */
    public function test_employees_are_filtered_by_the_selected_office_id(): void
    {
        $mine = $this->office(1, 10, 'Office A');
        $theirs = $this->office(2, 10, 'Office B');

        $this->employee($mine, 1111, 'Mine');
        $this->employee($theirs, 2222, 'Theirs');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('agentes.index', ['oficina_id' => $mine->id]));

        $response->assertOk();
        $response->assertSee('1111');
        $response->assertDontSee('2222');
    }

    /**
     * The attendance list was filtered on idoficina alone, so both customers'
     * punches showed up under one office.
     */
    public function test_attendance_is_filtered_by_the_selected_office_id(): void
    {
        $mine = $this->office(1, 10, 'Office A');
        $theirs = $this->office(2, 10, 'Office B');

        $this->punch($this->device('SN-A', $mine), 1111);
        $this->punch($this->device('SN-B', $theirs), 2222);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('devices.attendance', ['oficina_id' => $mine->id]));

        $response->assertOk();
        $response->assertSee('1111');
        $response->assertDontSee('2222');
    }

    /**
     * The create form posts the office id, so the device lands under the empresa
     * that office belongs to — not under whichever idempresa a hidden field
     * happened to carry.
     */
    public function test_a_device_is_saved_under_the_empresa_of_the_chosen_office(): void
    {
        $this->office(1, 10, 'Office A');
        $theirs = $this->office(2, 10, 'Office B');

        $this->actingAs(User::factory()->create())
            ->post(route('devices.store'), [
                'name' => 'New terminal',
                'no_sn' => 'SN-NEW',
                'idreloj' => '7',
                'oficina_id' => $theirs->id,
            ])
            ->assertRedirect(route('devices.index'));

        $device = Device::where('serial_number', 'SN-NEW')->first();

        $this->assertNotNull($device);
        $this->assertSame(2, (int) $device->idempresa);
        $this->assertSame(10, (int) $device->idoficina);
    }

    /**
     * Deleting an office used to delete every device with that office number,
     * across every empresa — one customer's cleanup wiping another's terminals.
     */
    public function test_deleting_an_office_leaves_devices_of_the_same_number_in_another_empresa(): void
    {
        $mine = $this->office(1, 10, 'Office A');
        $theirs = $this->office(2, 10, 'Office B');

        $myDevice = $this->device('SN-A', $mine);
        $theirDevice = $this->device('SN-B', $theirs);

        // A queued command: device_commands.device_id points at devices, so it
        // has to be removed with the device or the delete fails.
        $myDevice->commands()->create(['command' => 1, 'type' => 'pull_check', 'data' => 'C:1:CHECK']);

        $this->actingAs(User::factory()->create())
            ->get(route('oficinas.delete', ['id' => $mine->id]))
            ->assertRedirect(route('devices.oficinas'));

        $this->assertNull(Device::find($myDevice->id));
        $this->assertSame(0, Command::where('device_id', $myDevice->id)->count());

        $this->assertNotNull(Device::find($theirDevice->id));
        $this->assertNotNull(Oficina::find($theirs->id));
        $this->assertNull(Oficina::find($mine->id));
    }

    /**
     * Old links and bookmarks carry selectedOficina + idempresa; they must keep
     * resolving to the same office.
     */
    public function test_the_legacy_office_pair_still_selects_the_same_office(): void
    {
        $mine = $this->office(1, 10, 'Office A');
        $theirs = $this->office(2, 10, 'Office B');

        $this->employee($mine, 1111, 'Mine');
        $this->employee($theirs, 2222, 'Theirs');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('agentes.index', ['selectedOficina' => 10, 'idempresa' => 2]));

        $response->assertOk();
        $response->assertSee('2222');
        $response->assertDontSee('1111');
    }
}
