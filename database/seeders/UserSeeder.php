<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Hassam (Admin)',
            'email' => 'admin@skillleo.test',
            'password' => 'password',
        ]);

        User::factory()->bidder()->create([
            'name' => 'Bidder',
            'email' => 'bidder@skillleo.test',
            'password' => 'password',
        ]);
    }
}
