<?php

namespace App\Jobs\Teardown;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;
use Illuminate\Support\Facades\File;

class DeleteTenantStorage extends TenantJob
{
    public static function label(): string
    {
        return 'Delete storage';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        File::deleteDirectory(base_path($tenant->storage_path));
    }
}
