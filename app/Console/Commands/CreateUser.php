<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Creates an admin-panel account. Public sign-up was removed (S1), so this
 * is the only way to add a user.
 */
class CreateUser extends Command
{
    protected $signature = 'user:create {email : Login email} {--name= : Display name}';

    protected $description = 'Create an admin-panel user (prompts for the password)';

    public function handle(): int
    {
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $this->argument('email'),
            'name' => $this->option('name') ?: $this->argument('email'),
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        // The User model's 'hashed' cast hashes the password on save.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $this->info("User #{$user->id} ({$user->email}) created.");

        return self::SUCCESS;
    }
}
