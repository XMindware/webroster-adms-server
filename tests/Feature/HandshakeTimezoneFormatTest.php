<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Oficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * devices.timezone_format decides whether the handshake sends a TimeZone line.
 *
 * The protocol wants an hour offset ("7"), and the office timezone is an IANA
 * name ("Asia/Jakarta"), so the line was dropped entirely rather than sent in a
 * shape no firmware can parse. That left a terminal unable to learn its
 * timezone at all: it keeps whatever it was configured with, drifts, and the
 * server has to order a clock correction on every poll.
 *
 * It is opt-in per device instead of always-on, because the fleet is not
 * uniform and a device that rejects the options block never settles into a
 * normal polling rhythm. Everything not explicitly flagged must therefore keep
 * receiving exactly the block it received before this existed.
 */
class HandshakeTimezoneFormatTest extends TestCase
{
    use RefreshDatabase;

    private function office(string $timezone = 'Asia/Jakarta'): Oficina
    {
        return Oficina::create([
            'idempresa' => 1,
            'idoficina' => 2,
            'ubicacion' => 'Jakarta',
            'public_url' => 'https://station.test',
            'token' => 'token',
            'iatacode' => 'CGK',
            'timezone' => $timezone,
        ]);
    }

    private function device(?string $timezoneFormat): Device
    {
        return Device::create([
            'serial_number' => 'SN-1',
            'idempresa' => 1,
            'idoficina' => 2,
            'idreloj' => '1',
            'name' => 'Test device',
            'timezone_format' => $timezoneFormat,
        ]);
    }

    private function handshakeBody(): string
    {
        return $this->get('/iclock/cdata?SN=SN-1&options=all')->assertOk()->getContent();
    }

    /**
     * The TimeZone line as the terminal reads it. Lines end with CRLF, so the
     * pattern has to tolerate the trailing \r.
     */
    private function timezoneLine(string $body): ?string
    {
        return preg_match('/^TimeZone=([^\r\n]+)/m', $body, $m) === 1 ? $m[1] : null;
    }

    /**
     * Every device in production today is on the default, so this is the
     * regression that matters most: the options block must be byte-identical
     * to what it was before timezone_format existed.
     */
    public function test_a_device_on_the_default_gets_no_timezone_line(): void
    {
        $this->office();
        $this->device(null);

        $body = $this->handshakeBody();

        $this->assertNull($this->timezoneLine($body), 'the default must not start sending TimeZone');
        $this->assertStringNotContainsString('TimeZone=', $body);
    }

    /**
     * 'iana' is a real value an operator can pick, and it cannot be honoured:
     * the protocol wants an offset. Sending the office name back would be the
     * exact bug that made the line get dropped in the first place.
     */
    public function test_an_iana_format_is_not_sent_as_a_name(): void
    {
        $this->office('Asia/Jakarta');
        $this->device('iana');

        $body = $this->handshakeBody();

        $this->assertNull($this->timezoneLine($body));
        $this->assertStringNotContainsString('Asia/Jakarta', $body);
    }

    public function test_hours_sends_the_offset_in_hours(): void
    {
        $this->office('Asia/Jakarta');
        $this->device('hours');

        $body = $this->handshakeBody();

        $this->assertSame('7', $this->timezoneLine($body));
        $this->assertStringContainsString("TimeZone=7\r\n", $body);
    }

    public function test_minutes_sends_the_offset_in_minutes(): void
    {
        $this->office('Asia/Jakarta');
        $this->device('minutes');

        $this->assertSame('420', $this->timezoneLine($this->handshakeBody()));
    }

    /**
     * Half-hour zones are where an "hours" offset stops being a whole number.
     * Rounding it away would set the terminal 30 minutes off.
     */
    public function test_a_half_hour_offset_keeps_its_fraction(): void
    {
        $this->office('Asia/Kolkata');
        $this->device('hours');

        $this->assertSame('5.5', $this->timezoneLine($this->handshakeBody()));
    }

    /**
     * The offset follows the office, not config('app.timezone'): the office is
     * where the terminal physically is, and the two are allowed to differ.
     */
    public function test_the_offset_follows_the_office_not_the_app_timezone(): void
    {
        config(['app.timezone' => 'UTC']);

        $this->office('America/Cancun');
        $this->device('minutes');

        // Cancun is UTC-5 all year - no DST since 2015.
        $this->assertSame('-300', $this->timezoneLine($this->handshakeBody()));
    }

    /**
     * The feedback loop Oficina::timezoneIsGeneric() documents: an office
     * mis-recorded as UTC would have its terminal set 7 hours back, its punches
     * would then read as skewed, and the next poll would order another
     * correction. Handing it an offset is the same mistake as ordering it a
     * clock, so the line is left out and the operator is warned.
     */
    public function test_a_generic_office_timezone_is_refused_and_warned_about(): void
    {
        $warnings = [];
        Log::listen(function ($message) use (&$warnings) {
            $warnings[] = $message->message;
        });

        $this->office('UTC');
        $this->device('hours');

        $body = $this->handshakeBody();

        $this->assertNull($this->timezoneLine($body), 'a UTC office must not set the terminal to UTC');

        $this->assertContains(
            'handshake: TimeZone omitted, office has no local timezone',
            $warnings,
            'an operator has to be able to see why the line is missing'
        );
    }

    /**
     * A device whose office row is missing entirely - the offset cannot be
     * known, so guessing one is worse than saying nothing.
     */
    public function test_a_device_without_an_office_gets_no_timezone_line(): void
    {
        $this->device('hours');

        $this->assertNull($this->timezoneLine($this->handshakeBody()));
    }

    /**
     * A bad oficinas.timezone is rejected by PHP's DateTimeZone constructor and
     * would take the whole handshake down, leaving the terminal without any
     * config at all. It falls back to the app timezone instead - but only as
     * far as parsing goes: the fallback is what gets sent.
     */
    public function test_an_unparsable_office_timezone_does_not_break_the_handshake(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);

        $this->office('UTC+7');
        $this->device('hours');

        $response = $this->get('/iclock/cdata?SN=SN-1&options=all');

        $response->assertOk();
        $this->assertStringContainsString('GET OPTION FROM: SN-1', $response->getContent());
        $this->assertSame('7', $this->timezoneLine($response->getContent()));
    }

    /**
     * The rest of the block is what keeps a terminal polling; adding a line
     * must not disturb it.
     */
    public function test_the_rest_of_the_options_block_is_untouched(): void
    {
        $this->office('Asia/Jakarta');
        $this->device('hours');

        $body = $this->handshakeBody();

        foreach ([
            'GET OPTION FROM: SN-1',
            'Stamp=9999',
            'ErrorDelay=60',
            'Delay=30',
            'ResLogDay=18250',
            'ResLogDelCount=10000',
            'ResLogCount=50000',
            'TransTimes=00:00;14:05',
            'TransInterval=4',
            'TransFlag=' . config('adms.trans_flag'),
            'Realtime=1',
            'Encrypt=0',
        ] as $line) {
            $this->assertStringContainsString($line, $body, "{$line} must still be sent");
        }

        $this->assertSame(1, preg_match('/^OpStamp=(\d+)\r?$/m', $body), 'OpStamp must still be an integer');
    }
}
