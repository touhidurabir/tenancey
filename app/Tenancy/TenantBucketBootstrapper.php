<?php

namespace App\Tenancy;

use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\Storage\LocalBucketManager;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Points the tenant's recorded disk (tenants.storage_disk) at its own bucket while tenancy is
 * active, and makes it the default disk and medialibrary's disk:
 *  - S3/MinIO: the disk's `bucket`;
 *  - local: the disk's `root` = {tenancy.storage.local_root}/{bucket}.
 *
 * Horizon runs many tenants in one worker, so revert() restores every original value and drops
 * the cached disk instance.
 */
class TenantBucketBootstrapper implements TenancyBootstrapper
{
    /**
     * @var array<string, mixed>
     */
    protected array $original = [];

    protected ?string $disk = null;

    public function bootstrap(Tenant $tenant): void
    {
        /** @var \App\Models\Tenant $tenant */
        if (blank($tenant->storage_disk) || blank($tenant->storage_bucket)) {
            throw new RuntimeException("Tenant [{$tenant->getTenantKey()}] has no storage bucket recorded.");
        }

        $disk = $tenant->storage_disk;
        $manager = BucketManagers::forDisk($disk);

        $this->disk = $disk;
        $this->original = [
            'filesystems.default' => config('filesystems.default'),
            'media-library.disk_name' => config('media-library.disk_name'),
            "filesystems.disks.{$disk}" => config("filesystems.disks.{$disk}"),
        ];

        config([
            'filesystems.default' => $disk,
            'media-library.disk_name' => $disk,
            ...$manager instanceof LocalBucketManager
                ? ["filesystems.disks.{$disk}.root" => $manager->path($tenant->storage_bucket)]
                : ["filesystems.disks.{$disk}.bucket" => $tenant->storage_bucket],
        ]);

        Storage::forgetDisk($disk);

        if ($manager instanceof LocalBucketManager) {
            // S3/MinIO sign their own links. Local ones go through a tenant route, signed relative
            // (path + query) and made absolute with the tenant's own host (Tenant::url()).
            Storage::disk($disk)->buildTemporaryUrlsUsing(
                fn (string $path, DateTimeInterface $expiration) => $tenant->url(
                    URL::temporarySignedRoute('tenant.storage.local', $expiration, ['path' => $path], absolute: false),
                ),
            );
        }
    }

    public function revert(): void
    {
        if ($this->disk === null) {
            return;
        }

        config($this->original);
        Storage::forgetDisk($this->disk);

        $this->original = [];
        $this->disk = null;
    }
}
