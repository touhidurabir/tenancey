<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Runs database/migrations/tenant on the tenant's connection (its own MySQL user when it has
 * one, which also proves that user's grants work).
 */
class MigrateTenantDatabase extends TenantJob
{
    public static function label(): string
    {
        return 'Run migrations';
    }

    protected function process(Tenant $tenant): void
    {
        $exitCode = Artisan::call('tenants:migrate', ['--tenants' => [$tenant->uuid]]);

        if ($exitCode !== 0) {
            throw new RuntimeException('tenants:migrate failed: '.Artisan::output());
        }
    }
}
