<?php

namespace App\Tenancy\Storage;

/**
 * Creates and deletes one tenant's storage bucket on one disk. Both operations are idempotent:
 * provisioning and teardown steps may run more than once (Retry).
 */
interface BucketManager
{
    public function exists(string $bucket): bool;

    /**
     * No-op when the bucket already exists and is ours.
     */
    public function create(string $bucket): void;

    /**
     * Deletes everything in the bucket, then the bucket. No-op when it's already gone.
     */
    public function delete(string $bucket): void;
}
