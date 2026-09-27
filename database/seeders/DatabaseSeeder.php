<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local/demo data. In production create your admin with:
     *   php artisan crm:create-admin
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => UserRole::Admin,
                'is_available' => false,
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'agent@example.com'],
            [
                'name' => 'Aisyah (Agent)',
                'password' => 'password',
                'role' => UserRole::Agent,
            ],
        );
    }
}
