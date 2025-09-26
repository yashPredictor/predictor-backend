<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'yash@admin.com'],
            [
                'name'     => 'Yash Admin',
                'password' => Hash::make('12345678'),
            ]
        );
    }
}
