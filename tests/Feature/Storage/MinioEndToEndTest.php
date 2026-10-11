<?php

namespace Tests\Feature\Storage;

use App\Services\TenantTeardown;
use App\Tenancy\Storage\BucketManagers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\SignsInTenantUsers;
use Tests\Concerns\UsesMinio;
use Tests\TestCase;

#[Group('minio')]
class MinioEndToEndTest extends TestCase
{
    use SignsInTenantUsers, UsesMinio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessMinioIsReachable();
        $this->deleteTestBucketsOnMinio();
        Mail::fake();
        config()->set('filesystems.default', 'minio'); // new tenants go to MinIO
    }

    protected function tearDown(): void
    {
        $this->deleteTestBucketsOnMinio();

        parent::tearDown();
    }

    public function test_a_tenant_on_minio_gets_a_bucket_serves_a_signed_link_and_loses_the_bucket_on_teardown(): void
    {
        $user = $this->signInAs('acme');
        $tenant = $this->tenant('acme');
        $manager = BucketManagers::forDisk('minio');

        $this->assertSame('minio', $tenant->storage_disk);
        $this->assertTrue($manager->exists($tenant->storage_bucket));

        $this->post($this->tenantUrl('acme', '/files'), ['file' => UploadedFile::fake()->createWithContent('report.txt', 'from minio')]);
        $media = $tenant->run(fn () => $user->fresh()->getFirstMedia('files'));

        $link = $this->get($this->tenantUrl('acme', "/files/{$media->id}/download"))->headers->get('Location');
        $this->assertStringStartsWith(config('filesystems.disks.minio.endpoint').'/'.$tenant->storage_bucket.'/', $link);

        $response = Http::get($link); // a real request to MinIO, like the browser's
        $this->assertSame('from minio', $response->body());
        $this->assertStringContainsString('attachment', $response->header('Content-Disposition'));

        app(TenantTeardown::class)->teardown($tenant);
        $this->assertFalse($manager->exists($tenant->storage_bucket));
    }
}
