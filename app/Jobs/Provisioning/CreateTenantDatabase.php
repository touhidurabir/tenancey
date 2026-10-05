<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;

class CreateTenantDatabase extends TenantJob
{
    public static function label(): string
    {
        return 'Create database';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        $manager = $this->databaseManager($tenant);

        if ($manager->databaseExists($tenant->db_name)) {
            $this->skip("database [{$tenant->db_name}] already exists (earlier attempt)");

            return;
        }

        $manager->createDatabase($tenant);
        $this->log('info', 'Created database', ['db_name' => $tenant->db_name]);
    }
}
