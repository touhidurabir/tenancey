<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class SeedTenantDatabase extends TenantJob
{
    public static function label(): string
    {
        return 'Seed database';
    }

    protected function process(Tenant $tenant): void
    {
        $exitCode = Artisan::call('tenants:seed', ['--tenants' => [$tenant->uuid], '--force' => true]);

        if ($exitCode !== 0) {
            throw new RuntimeException('tenants:seed failed: '.Artisan::output());
        }
    }
}
