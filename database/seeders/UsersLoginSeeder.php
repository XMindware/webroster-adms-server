<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User; // Import model User
use Illuminate\Support\Facades\Hash;

class UsersLoginSeeder extends Seeder
{
    public function run(): void
    {
        // Menghapus data lama (opsional)
        User::truncate();

        // Membuat user admin
        User::create([
            'id' => 1,
            'name' => 'Administrator',
            'email' => 'admin@admin.com',
            'password' => Hash::make('admin@adm'), // Menggunakan Hash agar lebih aman
            'email_verified_at' => now(),
        ]);
    }
}