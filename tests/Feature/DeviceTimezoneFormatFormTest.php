<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Oficina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The device form gained a TimeZone selector.
 *
 * devices.timezone_format decides whether the ADMS handshake sends a TimeZone
 * line and in what shape (see iclockController::handshakeTimezone()), but the
 * column had no way in from the UI - setting it meant an UPDATE by hand in the
 * database. Upstream added the selector in 483e670; these tests pin the
 * behaviour our screens have to keep, which is not quite upstream's:
 *
 *   - an empty choice sends no line at all, so every terminal already in the
 *     field keeps the options block it has today;
 *   - the number itself comes from the office timezone, not from a fixed
 *     offset, so the options cannot promise a specific "-6".
 */
class DeviceTimezoneFormatFormTest extends TestCase
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

    private function device(?string $format = null): Device
    {
        return Device::create([
            'serial_number' => 'SN-1',
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
            'name' => 'Buaran',
            'timezone_format' => $format,
        ]);
    }

    private function actingUser(): self
    {
        return $this->actingAs(User::factory()->create());
    }

    /**
     * Blade keeps the whitespace that surrounds an @if, so a selected option
     * comes out as `value="hours"  selected >` - two spaces, not one. Collapse
     * whitespace before comparing, so the assertion is about the attribute
     * being there and not about how the template happens to be spaced.
     */
    private function assertOptionSelected(string $value, TestResponse $response): void
    {
        $html = preg_replace('/\s+/', ' ', $response->getContent());

        $this->assertStringContainsString("value=\"{$value}\" selected", $html);
    }

    private function assertOptionNotSelected(string $value, TestResponse $response): void
    {
        $html = preg_replace('/\s+/', ' ', $response->getContent());

        $this->assertStringNotContainsString("value=\"{$value}\" selected", $html);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function acceptedFormats(): array
    {
        return [
            'hours' => ['hours'],
            'minutes' => ['minutes'],
        ];
    }

    #[DataProvider('acceptedFormats')]
    public function test_the_create_form_stores_the_chosen_format(string $format): void
    {
        $this->office();

        $this->actingUser()->post(route('devices.store'), [
            'name' => 'Buaran',
            'no_sn' => 'SN-9',
            'idreloj' => '9',
            'idoficina' => 2,
            'idempresa' => 1,
            'timezone_format' => $format,
        ]);

        $this->assertSame($format, Device::where('serial_number', 'SN-9')->first()->timezone_format);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rejectedFormats(): array
    {
        return [
            'nothing chosen' => [''],
            'the old iana marker' => ['iana'],
            'a timezone name' => ['Asia/Jakarta'],
            'wrong case' => ['HOURS'],
            'a bare offset' => ['7'],
        ];
    }

    #[DataProvider('rejectedFormats')]
    public function test_the_create_form_clears_anything_a_terminal_cannot_parse(string $format): void
    {
        $this->office();

        $this->actingUser()->post(route('devices.store'), [
            'name' => 'Buaran',
            'no_sn' => 'SN-9',
            'idreloj' => '9',
            'idoficina' => 2,
            'idempresa' => 1,
            'timezone_format' => $format,
        ]);

        // Only 'hours' and 'minutes' reach the handshake; anything else would
        // sit in the column, be ignored there, and still make the edit form
        // show a blank selection over a value that is really present.
        $this->assertNull(Device::where('serial_number', 'SN-9')->first()->timezone_format);
    }

    public function test_the_edit_form_stores_the_chosen_format_and_can_take_it_back(): void
    {
        $this->office();
        $device = $this->device();

        $this->actingUser()->post(route('devices.update', ['id' => $device->id]), [
            'name' => 'Buaran',
            'serial_number' => 'SN-1',
            'idreloj' => '1',
            'idoficina' => 2,
            'idempresa' => 1,
            'timezone_format' => 'minutes',
        ]);

        $this->assertSame('minutes', $device->fresh()->timezone_format);

        // Choosing the empty option again has to undo it, otherwise a wrong
        // choice could never be taken back from the UI.
        $this->actingUser()->post(route('devices.update', ['id' => $device->id]), [
            'name' => 'Buaran',
            'serial_number' => 'SN-1',
            'idreloj' => '1',
            'idoficina' => 2,
            'idempresa' => 1,
            'timezone_format' => '',
        ]);

        $this->assertNull($device->fresh()->timezone_format);
    }

    public function test_the_edit_form_preselects_the_stored_format(): void
    {
        $this->office();
        $device = $this->device('hours');

        $response = $this->actingUser()->get(route('devices.edit', ['id' => $device->id]));

        $response->assertOk();
        $this->assertOptionSelected('hours', $response);
        $this->assertOptionNotSelected('minutes', $response);
        $this->assertOptionNotSelected('', $response);
    }

    public function test_the_create_form_offers_the_default_as_the_selected_choice(): void
    {
        $this->office();

        $response = $this->actingUser()->get(route('devices.create'));

        $response->assertOk();
        $this->assertOptionSelected('', $response);
        $this->assertOptionNotSelected('hours', $response);
        $this->assertOptionNotSelected('minutes', $response);
    }
}
