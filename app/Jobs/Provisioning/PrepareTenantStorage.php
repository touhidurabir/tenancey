<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;
use Illuminate\Support\Facades\File;

class PrepareTenantStorage extends TenantJob
{
    public static function label(): string
    {
        return 'Prepare storage';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        foreach (['app/public', 'framework/cache', 'framework/views', 'logs'] as $folder) {
            File::ensureDirectoryExists(base_path($tenant->storage_path.'/'.$folder));
        }
    }
}
