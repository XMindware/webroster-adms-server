<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S9: login must be rate limited and must accept long passwords.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple-2026';

    private function makeUser(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    public function test_password_longer_than_twelve_characters_can_log_in(): void
    {
        $user = $this->makeUser();

        $this->post('/login-user', ['email' => 'admin@example.com', 'password' => self::PASSWORD])
            ->assertRedirect('devices');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_locked_after_five_failed_attempts(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login-user', ['email' => 'admin@example.com', 'password' => 'wrong-password-'.$i]);
        }

        // Sixth attempt with the right password is still refused.
        $this->post('/login-user', ['email' => 'admin@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_lockout_is_per_email_and_ip(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login-user', ['email' => 'someone-else@example.com', 'password' => 'wrong-'.$i]);
        }

        $this->post('/login-user', ['email' => 'admin@example.com', 'password' => self::PASSWORD])
            ->assertRedirect('devices');

        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login-user', ['email' => 'admin@example.com', 'password' => 'wrong-'.$i]);
        }
        $this->post('/login-user', ['email' => 'admin@example.com', 'password' => self::PASSWORD])
            ->assertRedirect('devices');
        $this->get('/logout');

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login-user', ['email' => 'admin@example.com', 'password' => 'wrong-again-'.$i]);
        }
        $this->post('/login-user', ['email' => 'admin@example.com', 'password' => self::PASSWORD])
            ->assertRedirect('devices');
    }

    public function test_login_route_has_an_ip_level_throttle(): void
    {
        // Spraying many emails from one IP must also hit a limit.
        $last = null;
        for ($i = 0; $i < 25; $i++) {
            $last = $this->post('/login-user', ['email' => "user{$i}@example.com", 'password' => 'x']);
        }

        $this->assertSame(429, $last->status());
    }
}
