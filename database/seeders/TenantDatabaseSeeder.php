<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a freshly migrated tenant database (run by App\Jobs\Provisioning\SeedTenantDatabase).
 * The first user is NOT created here: CreateFirstTenantUser does that from the tenant's
 * pending_admin details and gives them the `admin` role. Must be safe to run more than once.
 *
 * Runs ONCE per tenant, at provisioning. A role or permission added here later never reaches
 * existing tenants: ship a tenant migration that backfills it.
 */
class TenantDatabaseSeeder extends Seeder
{
    /**
     * Roles every tenant starts with.
     *
     * @var list<string>
     */
    public const ROLES = ['admin', 'member'];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions(); // the rows changed
    }
}
