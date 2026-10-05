<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;
use RuntimeException;

class CreateTenantDatabaseUser extends TenantJob
{
    public static function label(): string
    {
        return 'Create database user';
    }

    protected function process(Tenant $tenant): void
    {
        if (! $tenant->hasDedicatedDatabaseUser()) {
            $this->skip('no dedicated user, the tenant connects with the master .env credentials');

            return;
        }

        TenantResourceGuard::assertOwned($tenant);

        $manager = $this->databaseUserManager($tenant);

        if ($manager->userExists($tenant->db_username)) {
            // Someone else's user (the form checks, but the server is shared): never touch it.
            if ($tenant->db_user_created_at === null) {
                throw new RuntimeException("Database user [{$tenant->db_username}] already exists and was not created for this tenant. Delete the tenant and choose another username.");
            }

            // Ours from an earlier attempt. CREATE USER and GRANT are two statements, so recreate
            // it to be sure both happened.
            TenantResourceGuard::assertDatabaseUserCreatedByUs($tenant);
            $manager->dropUser($tenant->db_username);
            $this->log('info', 'Dropped own database user from an earlier attempt, recreating it', ['db_username' => $tenant->db_username]);
        }

        $manager->createUser($tenant->db_username, $tenant->db_password, $tenant->db_name);

        $tenant->forceFill(['db_user_created_at' => $tenant->db_user_created_at ?? now()])->save();

        $this->log('info', 'Created database user', [
            'db_username' => $tenant->db_username,
            'db_master_access' => $tenant->db_master_access,
        ]);
    }
}
