<?php

namespace Tests\Feature\Storage;

use App\Tenancy\Storage\BucketManagers;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesMinio;
use Tests\TestCase;

#[Group('minio')]
class S3BucketManagerTest extends TestCase
{
    use UsesMinio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessMinioIsReachable();
        $this->deleteTestBucketsOnMinio();
    }

    protected function tearDown(): void
    {
        $this->deleteTestBucketsOnMinio();

        parent::tearDown();
    }

    public function test_a_bucket_is_created_once_and_deleted_even_when_not_empty(): void
    {
        $manager = BucketManagers::forDisk('minio');

        $this->assertFalse($manager->exists('tenanceytest-probe'));

        $manager->create('tenanceytest-probe');
        $manager->create('tenanceytest-probe'); // a retry: already ours, no error
        $this->assertTrue($manager->exists('tenanceytest-probe'));

        $client = Storage::disk('minio')->getClient();
        foreach (['a.txt', 'nested/b.txt'] as $key) {
            $client->putObject(['Bucket' => 'tenanceytest-probe', 'Key' => $key, 'Body' => 'x']);
        }

        $manager->delete('tenanceytest-probe');
        $manager->delete('tenanceytest-probe'); // already gone: no error
        $this->assertFalse($manager->exists('tenanceytest-probe'));
    }
}
