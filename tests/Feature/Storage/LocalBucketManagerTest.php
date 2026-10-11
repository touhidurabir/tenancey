<?php

namespace Tests\Feature\Storage;

use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\Storage\LocalBucketManager;
use App\Tenancy\Storage\S3BucketManager;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class LocalBucketManagerTest extends TestCase
{
    public function test_managers_are_chosen_by_the_disks_driver(): void
    {
        $this->assertInstanceOf(LocalBucketManager::class, BucketManagers::forDisk('local'));
        $this->assertInstanceOf(S3BucketManager::class, BucketManagers::forDisk('minio'));
        $this->assertInstanceOf(S3BucketManager::class, BucketManagers::forDisk('s3'));
    }

    public function test_a_local_bucket_is_a_folder_that_can_be_created_and_deleted_twice(): void
    {
        $manager = BucketManagers::forDisk('local');
        $path = storage_path('app/buckets/tenanceytest-probe');

        $this->assertFalse($manager->exists('tenanceytest-probe'));

        $manager->create('tenanceytest-probe');
        $manager->create('tenanceytest-probe');
        $this->assertDirectoryExists($path);
        File::put($path.'/a.txt', 'x');

        $manager->delete('tenanceytest-probe');
        $manager->delete('tenanceytest-probe');
        $this->assertDirectoryDoesNotExist($path);
    }

    public function test_local_bucket_names_cannot_escape_the_root(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BucketManagers::forDisk('local')->delete('../tenanceytest-x');
    }
}
