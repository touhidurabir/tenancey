<?php

namespace App\Tenancy\Storage;

use InvalidArgumentException;

/**
 * Picks the bucket manager for a disk by the disk's driver (config `tenancy.storage.bucket_managers`):
 * the `minio` and `s3` disks both use the `s3` driver, so both get S3BucketManager.
 */
class BucketManagers
{
    public static function forDisk(string $disk): BucketManager
    {
        $driver = config("filesystems.disks.{$disk}.driver");
        $class = config("tenancy.storage.bucket_managers.{$driver}");

        if (! is_string($class)) {
            throw new InvalidArgumentException("No bucket manager for disk [{$disk}] (driver [{$driver}]).");
        }

        return new $class($disk);
    }

    /**
     * S3's bucket name rules, which local folder names follow too (no slashes, no dots, so no
     * path can escape the root).
     */
    public static function assertValidName(string $bucket): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new InvalidArgumentException("Invalid bucket name [{$bucket}].");
        }
    }
}
