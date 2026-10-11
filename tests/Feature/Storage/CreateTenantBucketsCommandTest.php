<?php

namespace Tests\Feature\Storage;

use App\Services\TenantTeardown;
use App\Tenancy\Storage\BucketManagers;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CreateTenantBucketsCommandTest extends TestCase
{
    public function test_it_creates_missing_buckets_for_live_tenants_only(): void
    {
        Mail::fake();
        $acme = $this->createTenant('acme');
        $gone = $this->createTenant('gone');
        app(TenantTeardown::class)->teardown($gone);
        $manager = BucketManagers::forDisk('local');
        $manager->delete($acme->storage_bucket); // as for a tenant made before buckets existed

        $this->artisan('tenants:create-buckets')->assertSuccessful();
        $this->artisan('tenants:create-buckets')->assertSuccessful();

        $this->assertTrue($manager->exists($acme->storage_bucket));
        $this->assertFalse($manager->exists($gone->storage_bucket));
    }
}
