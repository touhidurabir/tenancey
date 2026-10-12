<?php

namespace Tests\Feature\Storage;

use App\Tenancy\TenantResourceGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TenantBucketRecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provisioning_records_the_disk_and_bucket(): void
    {
        $tenant = $this->createTenant('acme');

        $this->assertSame('local', $tenant->storage_disk);
        $this->assertSame('tenanceytest-'.$tenant->uuid, $tenant->storage_bucket);
    }

    public function test_bucket_names_need_a_valid_prefix(): void
    {
        config()->set('tenancy.storage.bucket_prefix', 'Bad_Prefix');

        $this->expectException(InvalidArgumentException::class);

        TenantResourceGuard::bucketNameFor('0199c3a5-0000-7000-8000-000000000000');
    }

    public function test_the_guard_refuses_a_bucket_that_is_not_exactly_the_tenants(): void
    {
        $tenant = $this->createTenant('acme');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_bucket' => 'tenanceytest-someone-else']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage_bucket');

        TenantResourceGuard::assertBucketOwned($tenant->fresh());
    }

    public function test_the_guard_refuses_an_unknown_disk(): void
    {
        $tenant = $this->createTenant('acme');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_disk' => 'ftp']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage_disk');

        TenantResourceGuard::assertBucketOwned($tenant->fresh());
    }

    public function test_database_and_folder_steps_do_not_depend_on_the_bucket_settings(): void
    {
        $tenant = $this->createTenant('acme');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_disk' => 'ftp']);

        TenantResourceGuard::assertOwned($tenant->fresh()); // a bad disk must not block teardown's database steps

        $this->addToAssertionCount(1);
    }

    public function test_provisioning_refuses_a_default_disk_it_cannot_make_buckets_on(): void
    {
        config()->set('filesystems.default', 'S3'); // a typo in FILESYSTEM_DISK

        try {
            $this->createTenant('acme');
            $this->fail('Expected provisioning to refuse the disk.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('[S3]', $e->getMessage());
        }

        $this->assertDatabaseCount('tenants', 0); // no row that could never be torn down
    }
}
