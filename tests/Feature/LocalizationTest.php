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

    // -----------------------------------------------------------------------
    // Response messages (flash / JSON), not just the static labels.
    //
    // The controllers used to answer with Spanish literals, so an English or
    // Indonesian user got Spanish confirmations. Every message now goes
    // through __(), and these tests pin one message per locale for each
    // entry point that used to be hardcoded.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array<int, string>>
     */
    public static function deviceUpdateErrorMessages(): array
    {
        return [
            'en' => ['en', 'Oficina not found'],
            'es' => ['es', 'Oficina no encontrada'],
            'id' => ['id', 'Kantor tidak ditemukan'],
        ];
    }

    #[DataProvider('deviceUpdateErrorMessages')]
    public function test_device_update_error_message_follows_the_locale(string $locale, string $expected): void
    {
        $this->office();
        $device = $this->device();

        $response = $this->actingUser()->post("/devices/{$device->id}/update?lang={$locale}", [
            'idoficina' => 999, // no such office
        ]);

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('error', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function deviceUpdateSuccessMessages(): array
    {
        return [
            'en' => ['en', 'Device updated successfully.'],
            'es' => ['es', 'Dispositivo actualizado exitosamente.'],
            'id' => ['id', 'Perangkat berhasil diperbarui.'],
        ];
    }

    #[DataProvider('deviceUpdateSuccessMessages')]
    public function test_device_update_success_message_follows_the_locale(string $locale, string $expected): void
    {
        $this->office();
        $device = $this->device();

        $response = $this->actingUser()->post("/devices/{$device->id}/update?lang={$locale}", [
            'idoficina' => 2,
            'name' => 'Halobayi Buaran',
            'serial_number' => 'SN-1',
            'idreloj' => '1',
        ]);

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('success', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function oficinaStoreMessages(): array
    {
        return [
            'en' => ['en', 'Oficina created successfully.'],
            'es' => ['es', 'Oficina creada exitosamente.'],
            'id' => ['id', 'Kantor berhasil dibuat.'],
        ];
    }

    #[DataProvider('oficinaStoreMessages')]
    public function test_oficina_store_message_follows_the_locale(string $locale, string $expected): void
    {
        $response = $this->actingUser()->post("/oficinas/store?lang={$locale}", [
            'idempresa' => 1,
            'idoficina' => 5,
            'ubicacion' => 'Merida',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'MID',
            'city_timezone' => 'America/Merida',
            'timezone' => 'America/Merida',
        ]);

        $response->assertRedirect(route('devices.oficinas'));
        $response->assertSessionHas('success', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function webhookStoreMessages(): array
    {
        return [
            'en' => ['en', 'Webhook created successfully'],
            'es' => ['es', 'Webhook creado correctamente'],
            'id' => ['id', 'Webhook berhasil dibuat'],
        ];
    }

    #[DataProvider('webhookStoreMessages')]
    public function test_webhook_store_message_follows_the_locale(string $locale, string $expected): void
    {
        $this->office();
        $device = $this->device();

        $response = $this->actingUser()->post("/webhooks/store?lang={$locale}", [
            'device_id' => $device->id,
            'url' => 'https://example.test/hook',
        ]);

        $response->assertRedirect(route('webhooks.index'));
        $response->assertSessionHas('success', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function noDevicesForOfficeMessages(): array
    {
        return [
            'en' => ['en', 'No devices found for this office'],
            'es' => ['es', 'No se encontraron dispositivos para esta oficina'],
            'id' => ['id', 'Tidak ada perangkat ditemukan untuk kantor ini'],
        ];
    }

    #[DataProvider('noDevicesForOfficeMessages')]
    public function test_delete_employee_without_devices_message_follows_the_locale(string $locale, string $expected): void
    {
        $this->office();

        $response = $this->actingUser()->post("/devices/delete/employee?lang={$locale}", [
            'idagente' => '123',
            'oficina' => 99, // no devices belong to this office
        ]);

        $response->assertSessionHas('error', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function loginFailureMessages(): array
    {
        return [
            'en' => ['en', 'Email or password is incorrect.'],
            'es' => ['es', 'El correo o la contraseña son incorrectos.'],
            'id' => ['id', 'Email atau kata sandi salah.'],
        ];
    }

    #[DataProvider('loginFailureMessages')]
    public function test_login_failure_message_follows_the_locale(string $locale, string $expected): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $response = $this->post("/login-user?lang={$locale}", [
            'email' => 'known@example.com',
            // 10 chars: the rule is min:8|max:12, so this reaches the
            // credential check instead of failing validation first.
            'password' => 'wrongpass1',
        ]);

        $response->assertSessionHas('fail', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function loginRequiredMessages(): array
    {
        return [
            'en' => ['en', 'You have to log in first.'],
            'es' => ['es', 'Debe iniciar sesión primero.'],
            'id' => ['id', 'Anda harus masuk terlebih dahulu.'],
        ];
    }

    #[DataProvider('loginRequiredMessages')]
    public function test_guest_is_told_to_log_in_in_the_active_locale(string $locale, string $expected): void
    {
        $response = $this->get("/registration?lang={$locale}");

        $response->assertRedirect('login');
        $response->assertSessionHas('fail', $expected);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function attendanceNotFoundMessages(): array
    {
        return [
            'en' => ['en', 'Record not found'],
            'es' => ['es', 'Registro no encontrado'],
            'id' => ['id', 'Data tidak ditemukan'],
        ];
    }

    #[DataProvider('attendanceNotFoundMessages')]
    public function test_fix_attendance_json_message_follows_the_locale(string $locale, string $expected): void
    {
        $this->office();

        $response = $this->actingUser()
            ->getJson("/devices/retrieve/attendance/fix/999999?lang={$locale}");

        $response->assertNotFound();
        $response->assertJson([
            'success' => false,
            'message' => $expected,
        ]);
    }
}
