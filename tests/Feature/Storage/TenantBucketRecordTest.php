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

        TenantResourceGuard::assertOwned($tenant->fresh());
    }

    public function test_the_guard_refuses_an_unknown_disk(): void
    {
        $tenant = $this->createTenant('acme');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_disk' => 'ftp']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage_disk');

        TenantResourceGuard::assertOwned($tenant->fresh());
    }
}
