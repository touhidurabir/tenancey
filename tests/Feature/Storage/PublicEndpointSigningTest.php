<?php

namespace Tests\Feature\Storage;

use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\Storage\S3PublicUrlSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesMinio;
use Tests\TestCase;

class PublicEndpointSigningTest extends TestCase
{
    use UsesMinio;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * A tenant recorded on `minio`, without creating a bucket: signing never connects.
     */
    protected function minioTenant(string $subdomain): Tenant
    {
        $tenant = $this->createTenant($subdomain);
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_disk' => 'minio']);

        return $tenant->fresh();
    }

    public function test_links_are_signed_for_the_public_endpoint_with_their_download_options(): void
    {
        config()->set('filesystems.disks.minio.endpoint', 'http://minio:9000');
        config()->set('filesystems.disks.minio.public_endpoint', 'http://localhost:19010');
        $tenant = $this->minioTenant('acme');

        tenancy()->initialize($tenant);
        $url = Storage::temporaryUrl('1/report.txt', now()->addMinutes(5), ['ResponseContentDisposition' => 'attachment; filename="report.txt"']);
        tenancy()->end();

        $this->assertStringStartsWith("http://localhost:19010/{$tenant->storage_bucket}/1/report.txt?", $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringContainsString('response-content-disposition=attachment', $url);
    }

    public function test_without_a_public_endpoint_links_are_signed_for_the_endpoint_and_nothing_leaks_across_tenants(): void
    {
        config()->set('filesystems.disks.minio.endpoint', 'http://minio:9000');
        config()->set('filesystems.disks.minio.public_endpoint', 'http://localhost:19010');
        $acme = $this->minioTenant('acme');
        $beta = $this->minioTenant('beta');

        tenancy()->initialize($acme);
        Storage::temporaryUrl('a.txt', now()->addMinutes(5));
        tenancy()->end();

        config()->set('filesystems.disks.minio.public_endpoint', null); // as on Valet
        tenancy()->initialize($beta);
        $url = Storage::temporaryUrl('a.txt', now()->addMinutes(5));
        tenancy()->end();

        $this->assertStringStartsWith("http://minio:9000/{$beta->storage_bucket}/a.txt?", $url);
    }

    #[Group('minio')]
    public function test_a_link_works_only_on_the_host_it_was_signed_for(): void
    {
        $this->skipUnlessMinioIsReachable();
        $endpoint = (string) config('filesystems.disks.minio.endpoint');
        if (parse_url($endpoint, PHP_URL_HOST) !== '127.0.0.1') {
            $this->markTestSkipped('Needs the Mac\'s MinIO, reachable as both 127.0.0.1 and localhost.');
        }
        $this->deleteTestBucketsOnMinio();

        try {
            BucketManagers::forDisk('minio')->create('tenanceytest-signing');
            $disk = [...config('filesystems.disks.minio'), 'bucket' => 'tenanceytest-signing'];
            Storage::build($disk)->put('probe.txt', 'signed for localhost');

            $public = str_replace('127.0.0.1', 'localhost', $endpoint);
            $url = S3PublicUrlSigner::temporaryUrl([...$disk, 'public_endpoint' => $public], 'probe.txt', now()->addMinutes(5));

            $this->assertSame('signed for localhost', Http::get($url)->body());
            $this->assertSame(403, Http::get(str_replace('localhost', '127.0.0.1', $url))->status()); // same server, other host: refused
        } finally {
            $this->deleteTestBucketsOnMinio();
        }
    }
}
