<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         User::create([
        'nom' => 'Admin',   
        'prenom' => 'Admin',  
        'email' => 'admin@universal-tours.com',
        'password' => bcrypt('password'),
        ])->assignRole('admin'); 
    }
}
