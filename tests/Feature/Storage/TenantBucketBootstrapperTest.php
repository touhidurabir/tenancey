<?php

namespace Tests\Feature\Storage;

use App\Enums\TenantState;
use App\Models\Tenant;
use App\Tenancy\TenantBucketBootstrapper;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TenantBucketBootstrapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_each_tenant_writes_to_its_own_bucket_and_central_is_restored(): void
    {
        $acme = $this->createTenant('acme');
        $beta = $this->createTenant('beta');
        $centralRoot = config('filesystems.disks.local.root');

        tenancy()->initialize($acme);
        $this->assertSame('local', config('media-library.disk_name'));
        Storage::put('probe.txt', 'acme');

        tenancy()->initialize($beta); // a worker switching tenants without ending first
        $this->assertFalse(Storage::exists('probe.txt'));
        Storage::put('probe.txt', 'beta');

        tenancy()->end();

        $this->assertSame($centralRoot, config('filesystems.disks.local.root'));
        $this->assertFalse(Storage::exists('probe.txt'));
        $this->assertSame('acme', file_get_contents(storage_path("app/buckets/{$acme->storage_bucket}/probe.txt")));
        $this->assertSame('beta', file_get_contents(storage_path("app/buckets/{$beta->storage_bucket}/probe.txt")));
    }

    public function test_a_tenant_without_a_recorded_bucket_is_refused(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'state' => TenantState::Ready,
            'db_name' => 'tenanceytest_x',
            'storage_path' => 'storage/testtenant_x',
            'cache_prefix' => 'tenanceytest_x:',
        ]);

        $this->expectException(RuntimeException::class);

        app(TenantBucketBootstrapper::class)->bootstrap($tenant);
    }
}
