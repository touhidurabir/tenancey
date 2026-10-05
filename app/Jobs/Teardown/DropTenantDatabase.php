<?php

namespace App\Jobs\Teardown;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;

class DropTenantDatabase extends TenantJob
{
    public static function label(): string
    {
        return 'Drop database';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        $manager = $this->databaseManager($tenant);

        if (! $manager->databaseExists($tenant->db_name)) {
            $this->skip("database [{$tenant->db_name}] does not exist");

            return;
        }

        $manager->deleteDatabase($tenant);
        $this->log('info', 'Dropped database', ['db_name' => $tenant->db_name]);
    }
}
