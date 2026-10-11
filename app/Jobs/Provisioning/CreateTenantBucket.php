<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\TenantResourceGuard;

/**
 * The tenant's file bucket on its recorded disk: a real bucket on S3/MinIO, a folder on local.
 */
class CreateTenantBucket extends TenantJob
{
    public static function label(): string
    {
        return 'Create storage bucket';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        $manager = BucketManagers::forDisk($tenant->storage_disk);

        if ($manager->exists($tenant->storage_bucket)) {
            $this->skip("bucket [{$tenant->storage_bucket}] already exists");

            return;
        }

        $manager->create($tenant->storage_bucket);
    }
}
