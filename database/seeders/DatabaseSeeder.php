<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@universal-tours.com'],
            ['nom' => 'Admin','prenom' => 'Admin','password' => Hash::make('password'),'role' => 'admin']
        );

        User::updateOrCreate(
            ['email' => 'employee@universal-tours.com'],
            ['nom' => 'John','prenom' => 'Doe','password' => Hash::make('password'),'role' => 'employee']
        );
    }
}
