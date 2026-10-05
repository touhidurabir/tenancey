<?php

namespace App\Jobs\Teardown;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;

class DropTenantDatabaseUser extends TenantJob
{
    public static function label(): string
    {
        return 'Drop database user';
    }

    protected function process(Tenant $tenant): void
    {
        if (! $tenant->hasDedicatedDatabaseUser()) {
            $this->skip('no dedicated database user');

            return;
        }

        if ($tenant->db_user_created_at === null) {
            // Provisioning never created it (e.g. the name was taken), so it is not ours to drop.
            $this->skip("database user [{$tenant->db_username}] was never created by this tenant, leaving it alone");

            return;
        }

        TenantResourceGuard::assertDatabaseUserCreatedByUs($tenant);

        $this->databaseUserManager($tenant)->dropUser($tenant->db_username); // DROP USER IF EXISTS

        $this->log('info', 'Dropped database user', ['db_username' => $tenant->db_username]);
    }
}
