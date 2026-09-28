<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The device, office and webhook screens used to hardcode their labels, so
 * switching language left the fields unchanged. These tests pin the behaviour:
 * every label must come from the active locale.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function office(int $idempresa = 1, int $idoficina = 2): Oficina
    {
        return Oficina::create([
            'idempresa' => $idempresa,
            'idoficina' => $idoficina,
            'ubicacion' => 'Cancun',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CUN',
            'timezone' => 'America/Cancun',
        ]);
    }

    private function device(string $sn = 'SN-1'): Device
    {
        return Device::create([
            'serial_number' => $sn,
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
            'name' => 'Halobayi Buaran',
        ]);
    }

    private function actingUser(): self
    {
        return $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function deviceEditLabels(): array
    {
        return [
            'en' => ['en', 'Edit Device', 'Name', 'Serial Number', 'ID Clock', 'Office', 'Online', 'Update', 'Delete', 'Cancel'],
            'es' => ['es', 'Editar Dispositivo', 'Nombre', 'Número de Serie', 'ID Reloj', 'Oficina', 'En línea', 'Actualizar', 'Eliminar', 'Cancelar'],
            'id' => ['id', 'Edit Perangkat', 'Nama', 'Nomor Seri', 'ID Jam', 'Kantor Cabang', 'Online', 'Perbarui', 'Hapus', 'Batal'],
        ];
    }

    #[DataProvider('deviceEditLabels')]
    public function test_device_edit_labels_follow_the_locale(string $locale, string $heading, string $name, string $serial, string $id, string $office, string $online, string $update, string $delete, string $cancel): void
    {
        $this->office();
        $device = $this->device();

        $response = $this->actingUser()->get("/devices/{$device->id}/edit?lang={$locale}");

        $response->assertOk();
        $response->assertSee($heading, false);
        $response->assertSee(">{$name}<", false);
        $response->assertSee($serial, false);
        $response->assertSee($id, false);
        $response->assertSee($office, false);
        $response->assertSee($online, false);
        $response->assertSee($update, false);
        $response->assertSee($delete, false);
        $response->assertSee($cancel, false);

        // The old Spanish hardcoded labels must not leak into another locale.
        if ($locale !== 'es') {
            $response->assertDontSee('>Numero de Serie<', false);
        }
    }

    public function test_language_switch_route_stores_the_locale_and_changes_the_labels(): void
    {
        $this->office();
        $device = $this->device();

        $user = User::factory()->create();

        // Start in English.
        $english = $this->actingAs($user)->get("/devices/{$device->id}/edit?lang=en");
        $english->assertSee('Edit Device', false);
        $english->assertDontSee('Edit Perangkat', false);

        // Switch to Indonesian, then reload without ?lang — the session must win.
        $this->actingAs($user)
            ->get(route('language.switch', ['locale' => 'id']))
            ->assertRedirect();

        $indonesian = $this->actingAs($user)->get("/devices/{$device->id}/edit");
        $indonesian->assertSee('Edit Perangkat', false);
        $indonesian->assertDontSee('Edit Device', false);
    }

    public function test_office_create_labels_follow_the_locale(): void
    {
        $this->office();

        $en = $this->actingUser()->get('/oficinas/create?lang=en');
        $en->assertOk();
        $en->assertSee('Create Oficina', false);
        $en->assertSee('City Timezone', false);
        $en->assertSee('IATA Code', false);

        $id = $this->actingUser()->get('/oficinas/create?lang=id');
        $id->assertOk();
        $id->assertSee('Buat Kantor', false);
        $id->assertSee('Zona Waktu Kota', false);
        $id->assertSee('Kode IATA', false);
    }

    public function test_webhook_create_labels_follow_the_locale(): void
    {
        $this->office();
        $this->device();

        $en = $this->actingUser()->get('/webhooks/create?lang=en');
        $en->assertOk();
        $en->assertSee('Create Webhook', false);
        $en->assertSee('Webhook URL', false);

        $es = $this->actingUser()->get('/webhooks/create?lang=es');
        $es->assertOk();
        $es->assertSee('Crear Webhook', false);
        $es->assertSee('URL del Webhook', false);
    }

    public function test_delete_employee_labels_follow_the_locale(): void
    {
        $this->office();

        $en = $this->actingUser()->get('/devices/delete/employee?lang=en');
        $en->assertOk();
        $en->assertSee('Delete Employee Record from Device', false);
        $en->assertSee('Delete from Devices', false);

        $id = $this->actingUser()->get('/devices/delete/employee?lang=id');
        $id->assertOk();
        $id->assertSee('Hapus Data Karyawan dari Perangkat', false);
        $id->assertSee('Hapus dari Perangkat', false);
    }

    public function test_agent_pull_labels_follow_the_locale(): void
    {
        $this->office();

        $en = $this->actingUser()->get('/agentes/pull?lang=en');
        $en->assertOk();
        $en->assertSee('Pull Employees', false);
        $en->assertSee('Run Request', false);

        $es = $this->actingUser()->get('/agentes/pull?lang=es');
        $es->assertOk();
        $es->assertSee('Obtener Empleados', false);
        $es->assertSee('Ejecutar Solicitud', false);
    }
}
