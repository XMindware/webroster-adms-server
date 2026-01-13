<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UsersLoginTable extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->delete();
        
        DB::table('users')->insert(array (
            0 => 
            array (
                'created_at' => now(),
                'deleted_at' => NULL,                
                'email' => 'admin@admin.com',
                'email_verified_at' => NULL,                
                'id' => 1,
                'name' => 'Administrator',
                'password' => '$2y$10$3I90ONsRDQQ5vMQgd8b.H.QPEYDqHE6TKQCZi.cIFF7eEt9vPx0Rq', // admin@adm
                'remember_token' => NULL,
                'updated_at' => now(),
            )            
        ));
    }
}
