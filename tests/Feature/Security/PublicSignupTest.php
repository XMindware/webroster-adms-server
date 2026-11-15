<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * S1: account sign-up must not be reachable without logging in.
 */
class PublicSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_an_account(): void
    {
        $response = $this->post('/registration-user', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password123',
        ]);

        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_logged_in_user_cannot_create_an_account_over_http(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/registration-user', [
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => 'password123',
        ]);

        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseMissing('users', ['email' => 'other@example.com']);
    }

    public function test_registration_page_is_gone(): void
    {
        $this->get('/registration')->assertNotFound();
    }

    public function test_user_create_command_creates_a_user_with_a_hashed_password(): void
    {
        $this->artisan('user:create', [
            'email' => 'admin@example.com',
            '--name' => 'Admin',
        ])
            ->expectsQuestion('Password', 'a-long-passphrase-of-more-than-twelve-chars')
            ->expectsQuestion('Confirm password', 'a-long-passphrase-of-more-than-twelve-chars')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('Admin', $user->name);
        $this->assertTrue(Hash::check('a-long-passphrase-of-more-than-twelve-chars', $user->password));
    }

    public function test_user_create_command_rejects_mismatched_confirmation(): void
    {
        $this->artisan('user:create', ['email' => 'admin@example.com', '--name' => 'Admin'])
            ->expectsQuestion('Password', 'first-passphrase-1234')
            ->expectsQuestion('Confirm password', 'second-passphrase-1234')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_user_create_command_rejects_short_password_and_duplicate_email(): void
    {
        $this->artisan('user:create', ['email' => 'admin@example.com', '--name' => 'Admin'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('user:create', ['email' => 'taken@example.com', '--name' => 'Dup'])
            ->expectsQuestion('Password', 'a-long-enough-passphrase')
            ->expectsQuestion('Confirm password', 'a-long-enough-passphrase')
            ->assertFailed();
    }
}
