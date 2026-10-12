<?php

namespace App\Tenancy\Storage;

use Illuminate\Support\Facades\File;

/**
 * On the `local` driver a bucket is a folder: {tenancy.storage.local_root}/{bucket}.
 */
class LocalBucketManager implements BucketManager
{
    public function __construct(protected string $disk) {}

    public function path(string $bucket): string
    {
        BucketManagers::assertValidName($bucket);

        return rtrim((string) config('tenancy.storage.local_root'), '/').'/'.$bucket;
    }

    public function exists(string $bucket): bool
    {
        return File::isDirectory($this->path($bucket));
    }

    public function create(string $bucket): void
    {
        File::ensureDirectoryExists($this->path($bucket));
    }

    public function delete(string $bucket): void
    {
        File::deleteDirectory($this->path($bucket));
    }
}
