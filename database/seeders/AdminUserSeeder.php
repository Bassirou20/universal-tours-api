<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@universaltours.com'],
            [
                'name' => 'Admin Universal Tours',
                'password' => Hash::make('password123'),
                // adapte selon ton modèle User
            ]
        );
    }
}
