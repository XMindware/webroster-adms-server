<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Device contract: clocks POST to /iclock/* without a session or CSRF token.
 * Laravel skips CSRF checks in tests, so this test turns them back on;
 * without it a missing exemption only shows up as 419 on a real clock.
 */
class DeviceCsrfContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Not part of the ZKTeco protocol and unhardened until S8, so CSRF is
     * the only thing rejecting anonymous uploads. Remove an entry here only
     * together with the change that protects that route another way.
     */
    private const STILL_PROTECTED = ['iclock/upload-log'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_csrf_is_actually_enforced_for_browser_forms(): void
    {
        // Control: proves the override works, so the device test is meaningful.
        $this->post('/login-user', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(419);
    }

    public function test_device_post_routes_accept_requests_without_a_csrf_token(): void
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'iclock/') && in_array('POST', $route->methods(), true)) {
                $uris[] = $route->uri();
            }
        }

        $this->assertContains('iclock/querydata', $uris);

        foreach ($uris as $uri) {
            if (in_array($uri, self::STILL_PROTECTED, true)) {
                continue;
            }
            $status = $this->call('POST', "/{$uri}?SN=CSRF-TEST&table=ATTLOG", [], [], [], [
                'CONTENT_TYPE' => 'text/plain',
            ], "1\t2026-11-15 10:00:00\t0\t1")->status();

            $this->assertNotSame(419, $status, "POST /{$uri} was rejected by CSRF; clocks cannot send a token");
        }
    }

    public function test_upload_log_still_requires_a_csrf_token_until_s8(): void
    {
        $this->post('/iclock/upload-log?SN=CSRF-TEST')->assertStatus(419);
    }

    public function test_no_iclock_route_relies_on_a_login_session(): void
    {
        // Routes under iclock/ skip CSRF, so an admin page there would lose it.
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'iclock/')) {
                $this->assertNotContains('auth', $route->gatherMiddleware(), "{$route->uri()} uses auth under iclock/");
            }
        }
    }
}
