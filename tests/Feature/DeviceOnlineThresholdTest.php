<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * One threshold, two readers.
 *
 * devices:check-status hardcoded its own 3 minutes while the device status API
 * reads adms.online_threshold_minutes. Raising the setting would then have left
 * the API reporting a terminal as online that the alert command had already
 * declared offline - two answers to the same question, from the same data.
 *
 * The alert itself is a Slack log line, so the channel is muted to a NullHandler
 * and the assertions read the MessageLogged event instead: the level filter on
 * the real channel would otherwise decide whether the alert "happened".
 */
class DeviceOnlineThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.slack' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]]);
    }

    private function staleDevice(string $name = 'Buaran'): Device
    {
        return Device::create([
            'name' => $name,
            'serial_number' => 'SN-STALE',
            'idempresa' => '1',
            'idoficina' => '1',
            'idreloj' => '1',
            'online' => now()->subMinutes(30),
        ]);
    }

    /**
     * @return array<int, string> the warning messages logged during the run
     */
    private function warningsDuring(callable $callback): array
    {
        $messages = [];
        Log::listen(function ($message) use (&$messages) {
            if ($message->level === 'warning') {
                $messages[] = $message->message;
            }
        });

        $callback();

        return $messages;
    }

    public function test_alert_command_uses_the_configured_threshold(): void
    {
        config(['adms.online_threshold_minutes' => 60]);

        $this->staleDevice();

        // 30 minutes offline: past the old hardcoded 3, inside the configured 60.
        $warnings = $this->warningsDuring(
            fn () => $this->artisan('devices:check-status')->assertSuccessful()
        );

        $this->assertSame(
            [],
            $warnings,
            'a terminal inside the configured threshold must not be alerted as offline'
        );
    }

    public function test_alert_command_still_alerts_past_the_configured_threshold(): void
    {
        config(['adms.online_threshold_minutes' => 5]);

        $this->staleDevice();

        $warnings = $this->warningsDuring(
            fn () => $this->artisan('devices:check-status')->assertSuccessful()
        );

        $this->assertCount(1, $warnings, 'the alert itself must still fire');
        $this->assertStringContainsString('Buaran', $warnings[0]);
    }
}
