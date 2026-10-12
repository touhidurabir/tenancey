<?php

namespace App\Tenancy\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Real buckets on S3 or MinIO, through the disk's own S3 client (its endpoint, keys, region).
 */
class S3BucketManager implements BucketManager
{
    public function __construct(protected string $disk) {}

    public function exists(string $bucket): bool
    {
        BucketManagers::assertValidName($bucket);

        try {
            $this->client()->headBucket(['Bucket' => $bucket]);

            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e; // 403: it exists, but these keys don't own it
        }
    }

    public function create(string $bucket): void
    {
        if ($this->exists($bucket)) {
            return;
        }

        $params = ['Bucket' => $bucket];
        $region = config("filesystems.disks.{$this->disk}.region");
        if ($region && $region !== 'us-east-1') {
            $params['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
        }

        try {
            $this->client()->createBucket($params);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'BucketAlreadyOwnedByYou') {
                return; // a retry raced us
            }

            if ($e->getAwsErrorCode() === 'BucketAlreadyExists') {
                throw new RuntimeException("Bucket [{$bucket}] belongs to another account. Choose a unique TENANT_BUCKET_PREFIX.", previous: $e);
            }

            throw $e;
        }
    }

    public function delete(string $bucket): void
    {
        if (! $this->exists($bucket)) {
            return;
        }

        $client = $this->client();

        // S3 refuses to delete a non-empty bucket. Versions and delete markers cover versioned
        // buckets too; each page holds at most 1000 entries, DeleteObjects' limit.
        foreach ($client->getPaginator('ListObjectVersions', ['Bucket' => $bucket]) as $page) {
            $objects = array_map(
                fn (array $version) => ['Key' => $version['Key'], 'VersionId' => $version['VersionId']],
                [...($page['Versions'] ?? []), ...($page['DeleteMarkers'] ?? [])],
            );

            if ($objects === []) {
                continue;
            }

            $result = $client->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $objects, 'Quiet' => true]]);

            if (! empty($result['Errors'])) {
                throw new RuntimeException("Could not empty bucket [{$bucket}]: ".json_encode($result['Errors']));
            }
        }

        try {
            $client->deleteBucket(['Bucket' => $bucket]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchBucket') {
                throw $e;
            }
        }
    }

    protected function client(): S3Client
    {
        return Storage::disk($this->disk)->getClient();
    }
}
