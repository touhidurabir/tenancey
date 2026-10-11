<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\TenantResourceGuard;
use Illuminate\Console\Command;

class CreateTenantBuckets extends Command
{
    protected $signature = 'tenants:create-buckets';

    protected $description = "Create the storage bucket of every live tenant that doesn't have one yet (safe to re-run)";

    public function handle(): int
    {
        Tenant::query()->whereNotNull('storage_bucket')->orderBy('id')->each(function (Tenant $tenant) {
            TenantResourceGuard::assertOwned($tenant);

            BucketManagers::forDisk($tenant->storage_disk)->create($tenant->storage_bucket);

            $this->components->info("{$tenant->subdomain}: {$tenant->storage_disk} / {$tenant->storage_bucket}");
        });

        return self::SUCCESS;
    }
}
