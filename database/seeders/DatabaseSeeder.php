<?php

namespace Database\Seeders;

use App\Models\CentralUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the landlord (central) database. Tenant databases use TenantDatabaseSeeder.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        CentralUser::query()->firstOrCreate(
            ['email' => 'admin@tenancey.test'],
            ['name' => 'Central Admin', 'password' => 'password', 'email_verified_at' => now()],
        );
    }
}
