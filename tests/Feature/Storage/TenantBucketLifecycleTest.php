<?php

namespace Tests\Feature\Storage;

use App\Jobs\Provisioning\CreateTenantBucket;
use App\Jobs\Teardown\DeleteTenantBucket;
use App\Services\TenantTeardown;
use App\Tenancy\Storage\BucketManagers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class TenantBucketLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provisioning_creates_the_bucket_and_teardown_deletes_it(): void
    {
        $tenant = $this->createTenant('acme');
        $manager = BucketManagers::forDisk('local');

        $this->assertTrue($manager->exists($tenant->storage_bucket));

        app(TenantTeardown::class)->teardown($tenant);

        $this->assertFalse($manager->exists($tenant->storage_bucket));
    }

    public function test_both_steps_can_run_again(): void
    {
        $tenant = $this->createTenant('acme');

        (new CreateTenantBucket($tenant->id))->handle(); // exists: skips
        $this->assertTrue(BucketManagers::forDisk('local')->exists($tenant->storage_bucket));

        (new DeleteTenantBucket($tenant->id))->handle();
        (new DeleteTenantBucket($tenant->id))->handle(); // gone: nothing to do (half-provisioned teardown)
        $this->assertFalse(BucketManagers::forDisk('local')->exists($tenant->storage_bucket));
    }

    public function test_delete_refuses_a_bucket_that_is_not_exactly_the_tenants(): void
    {
        $tenant = $this->createTenant('acme');
        // Not another tenant's bucket: storage_bucket is unique, so the database already refuses that.
        $stranger = 'tenanceytest-someone-else';
        BucketManagers::forDisk('local')->create($stranger);
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_bucket' => $stranger]);

        try {
            (new DeleteTenantBucket($tenant->id))->handle();
            $this->fail('Expected the guard to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to touch tenant', $e->getMessage());
        }

        $this->assertTrue(BucketManagers::forDisk('local')->exists($stranger));
    }
}
