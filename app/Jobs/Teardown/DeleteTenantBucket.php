<?php

namespace App\Jobs\Teardown;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\TenantResourceGuard;

/**
 * Empties and deletes the tenant's bucket. Runs after the tenant database (and its `media` rows)
 * is gone, so it never relies on them: the bucket manager lists what is actually stored.
 */
class DeleteTenantBucket extends TenantJob
{
    public static function label(): string
    {
        return 'Delete storage bucket';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        $manager = BucketManagers::forDisk($tenant->storage_disk);

        if (! $manager->exists($tenant->storage_bucket)) {
            $this->skip("bucket [{$tenant->storage_bucket}] does not exist");

            return;
        }

        $manager->delete($tenant->storage_bucket);
    }
}
