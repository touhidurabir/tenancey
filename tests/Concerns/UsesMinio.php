<?php

namespace Tests\Concerns;

use App\Tenancy\Storage\BucketManagers;
use Illuminate\Support\Facades\Storage;

/**
 * For the `minio` test group: runs against the Mac's real MinIO (MINIO_* from .env), and is
 * skipped when it isn't reachable, so the rest of the suite never needs it.
 */
trait UsesMinio
{
    /**
     * Set by skipUnlessMinioIsReachable(); tearDown still runs after a skip, so cleanup checks it.
     */
    protected bool $minioIsReachable = false;

    protected function skipUnlessMinioIsReachable(): void
    {
        $endpoint = (string) config('filesystems.disks.minio.endpoint');
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT) ?: 80;

        if (blank(config('filesystems.disks.minio.key')) || ! $host || ! @fsockopen($host, $port, $code, $message, 1)) {
            $this->markTestSkipped("MinIO is not reachable at [{$endpoint}].");
        }

        $this->minioIsReachable = true;
    }

    /**
     * Test buckets only (tenanceytest-*): the Mac's MinIO is shared with other projects.
     */
    protected function deleteTestBucketsOnMinio(): void
    {
        if (! $this->minioIsReachable) {
            return;
        }

        $manager = BucketManagers::forDisk('minio');

        foreach (Storage::disk('minio')->getClient()->listBuckets()['Buckets'] ?? [] as $bucket) {
            if (str_starts_with($bucket['Name'], 'tenanceytest-')) {
                $manager->delete($bucket['Name']);
            }
        }
    }
}
