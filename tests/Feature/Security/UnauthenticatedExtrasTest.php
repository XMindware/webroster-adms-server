<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Device;
use App\Models\LogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S8: unauthenticated debug and upload endpoints.
 */
class UnauthenticatedExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_iclock_test_endpoint_is_removed(): void
    {
        $this->get('/iclock/test')->assertNotFound();
    }

    public function test_quick_status_does_not_expose_error_contents(): void
    {
        DB::table('error_log')->insert([
            'data' => 'SQLSTATE[HY000] password=hunter2 at /var/www/app/Secret.php',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/api/test')->assertOk();

        $response->assertJson(['status' => 'error']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertArrayNotHasKey('error', $response->json());
    }

    public function test_quick_status_reports_ok_without_errors(): void
    {
        $this->get('/api/test')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_upload_log_rejects_unknown_serial_numbers(): void
    {
        $this->post('/iclock/upload-log', [
            'sn' => 'NOT-REGISTERED',
            'file' => UploadedFile::fake()->createWithContent('log.txt', "2026-11-15 10:00:00 - INFO - hi\n"),
        ])->assertForbidden();

        $this->assertSame(0, LogEntry::count());
    }

    public function test_upload_log_requires_a_serial_number(): void
    {
        $this->post('/iclock/upload-log', [
            'file' => UploadedFile::fake()->createWithContent('log.txt', "line\n"),
        ])->assertStatus(422);

        $this->assertSame(0, LogEntry::count());
    }

    public function test_upload_log_rejects_oversized_files(): void
    {
        Device::create(['serial_number' => 'SN-LOG', 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);

        $this->post('/iclock/upload-log', [
            'sn' => 'SN-LOG',
            'file' => UploadedFile::fake()->create('log.txt', 2048, 'text/plain'),
        ])->assertStatus(422);

        $this->assertSame(0, LogEntry::count());
    }

    public function test_upload_log_accepts_a_small_log_from_a_registered_device(): void
    {
        Device::create(['serial_number' => 'SN-LOG', 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);

        $this->post('/iclock/upload-log', [
            'sn' => 'SN-LOG',
            'file' => UploadedFile::fake()->createWithContent('log.txt', "2026-11-15 10:00:00 - INFO - one\n2026-11-15 10:00:01 - INFO - two\n"),
        ])->assertOk()->assertJson(['status' => 'OK']);

        $this->assertSame(2, LogEntry::where('sn', 'SN-LOG')->count());
    }

    public function test_registered_device_can_upload_a_log_without_a_csrf_token(): void
    {
        // Clocks have no session. Laravel skips CSRF in tests, so re-enable it.
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        Device::create(['serial_number' => 'SN-LOG', 'idempresa' => 1, 'idoficina' => 2, 'idreloj' => '1']);

        $this->post('/iclock/upload-log', [
            'sn' => 'SN-LOG',
            'file' => UploadedFile::fake()->createWithContent('log.txt', "2026-11-15 10:00:00 - INFO - one\n"),
        ])->assertOk();

        // Unknown devices are still refused, now by the controller instead of CSRF.
        $this->post('/iclock/upload-log', [
            'sn' => 'NOT-REGISTERED',
            'file' => UploadedFile::fake()->createWithContent('log.txt', "line\n"),
        ])->assertForbidden();
    }
}
