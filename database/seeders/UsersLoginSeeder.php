<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersLoginSeeder extends Seeder
{
    public function run(): void
    {
        // Wipe the table so the seeder can be re-run (optional).
        User::truncate();

        User::create([
            'id' => 1,
            'name' => 'Administrator',
            'email' => 'admin@admin.com',
            'password' => Hash::make('admin@adm'), // hashed, never stored in plain text
            'email_verified_at' => now(),
        ]);
    }
}
