# Tenant Storage Buckets (Phase 1: Valet) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every tenant gets its own storage bucket (a folder on `local`, a real bucket on MinIO or S3) chosen by `FILESYSTEM_DISK`, and tenant users can upload an avatar and personal files through spatie/laravel-medialibrary. Valet runs on the Mac's MinIO.

**Architecture:** A per-driver `BucketManager` (`LocalBucketManager`, `S3BucketManager`) creates and deletes buckets. The provisioning step `CreateTenantBucket` and the teardown step `DeleteTenantBucket` call it, behind `TenantResourceGuard`. `TenantBucketBootstrapper` points the tenant's recorded disk at its bucket while tenancy is active. The tenant row records `storage_disk` and `storage_bucket`. medialibrary writes through that disk; downloads are temporary signed URLs (MinIO/S3 natively, `local` through a tenant route).

**Tech Stack:** Laravel 13, PHP 8.4, stancl/tenancy v3, spatie/laravel-medialibrary 11.23, league/flysystem-aws-s3-v3 3.x, MinIO (Mac, `127.0.0.1:19000`), PHPUnit, Blade + Tailwind.

**Spec:** `docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md`

## Global Constraints

- **Never commit or push** (the user's HARD RULE 1). Each task ends with a hand-off: print the `git add` / `git commit` lines for the developer, then **stop and wait** for them to review and commit before the next task (learning pace).
- Valet only. No `compose.yaml`, Dockerfile or nginx changes (phase 2).
- `FILESYSTEM_DISK` values: `local` | `minio` | `s3`. `minio` disk reads `MINIO_*`, `s3` reads `AWS_*`.
- Bucket name = `TENANT_BUCKET_PREFIX` + tenant uuid; prefix matches `/^[a-z0-9][a-z0-9-]*-$/`; whole name ≤ 63.
- Valet prefix `tenancey-`; tests `tenanceytest-` (`TestCase` refuses anything else).
- Local buckets live in `storage/app/buckets/{bucket}` (`tenancy.storage.local_root`).
- Every bucket create/delete goes through `TenantResourceGuard::assertOwned()`. Never delete buckets by pattern, except `tenanceytest-*` in test cleanup.
- The Mac's MinIO is shared: endpoint `http://127.0.0.1:19000`, user/password `minioadmin`/`minioadmin`.
- Avatar: jpeg/png/webp, ≤ 2 MB, `thumb` 128×128 `nonQueued()`. Files: any type, ≤ 10 MB. Temporary URLs: 5 minutes. Downloads as attachment.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes. Tests: `php artisan test --compact`.
- Never `config:cache`, `route:cache` or `optimize` (shared `bootstrap/cache`).
- No dependencies beyond `spatie/laravel-medialibrary` and `league/flysystem-aws-s3-v3` (approved in the spec).

## Review Focus

1. **A tenant switch in a long-lived worker leaks the previous tenant's bucket.** After `tenancy()->end()` or initializing another tenant, `Storage`, `filesystems.default` and `media-library.disk_name` must be back to central/the new tenant. Pinned in Task 5 (two tenants, revert).
2. **Teardown of a half-provisioned tenant** (failed before `CreateTenantBucket`) must not fail: delete of a missing bucket is a no-op. Pinned in Task 4.
3. **A bucket name that isn't exactly the tenant's** (edited row, prefix collision with another app on the shared MinIO) must be refused before any delete. Pinned in Task 2 (guard) and Task 4 (job).
4. **One user reaching another user's media** by id (download, delete) must get 404, and a tampered or expired local signed URL 403. Pinned in Tasks 7 and 9.
5. **A non-empty S3 bucket** must be emptied before `DeleteBucket` (S3 refuses otherwise). Pinned in Task 3's `minio` group.

---

### Task 1: Packages and medialibrary scaffolding

TDD exception (dependencies and published config, no behaviour yet): verified by the existing suite staying green.

**Files:**
- Modify: `composer.json` (`"php": "^8.4"`, new requires), `composer.lock`
- Create: `config/media-library.php` (published), `database/migrations/tenant/<timestamp>_create_media_table.php` (published, then moved)

- [ ] **Step 1: Raise the PHP constraint**

In `composer.json`, change `"php": "^8.3"` to `"php": "^8.4"`.

- [ ] **Step 2: Require the packages**

Run: `composer require spatie/laravel-medialibrary:^11.23 league/flysystem-aws-s3-v3:^3.0`
Expected: both installed (plus `aws/aws-sdk-php`, `spatie/image`), `package:discover` lists `spatie/laravel-medialibrary`.

- [ ] **Step 3: Publish config and migration**

Run: `php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"` and the same with `--tag="medialibrary-migrations"`.
Expected: `config/media-library.php` and `database/migrations/<timestamp>_create_media_table.php`. (If a tag name differs, list them with `php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider"`.)

- [ ] **Step 4: Move the migration to the tenant folder**

The `media` table belongs in each tenant database: `mv database/migrations/*_create_media_table.php database/migrations/tenant/`.

- [ ] **Step 5: Point medialibrary at FILESYSTEM_DISK, cap the size**

In `config/media-library.php`:
```php
    'disk_name' => env('FILESYSTEM_DISK', 'local'), // TenantBucketBootstrapper sets the tenant's recorded disk

    'max_file_size' => 1024 * 1024 * 10, // 10 MB
```

- [ ] **Step 6: Verify**

Run: `php artisan test --compact`
Expected: all tests pass (84), the central `media` migration does not exist (`ls database/migrations | grep media` prints nothing).

- [ ] **Step 7: Hand-off**

```bash
git add composer.json composer.lock config/media-library.php database/migrations/tenant/
git commit -m "Add medialibrary and the S3 driver"
```

---

### Task 2: Disks, bucket names, tenant columns and the guard

**Files:**
- Modify: `config/filesystems.php`, `config/tenancy.php`, `phpunit.xml`, `.env.example`, `.env` (local only, not committed), `app/Tenancy/TenantResourceGuard.php`, `app/Models/Tenant.php`, `app/Services/TenantProvisioner.php`, `resources/views/central/tenants/show.blade.php`, `tests/TestCase.php`
- Create: `database/migrations/2026_10_11_000000_add_storage_bucket_columns_to_tenants_table.php`, `tests/Feature/Storage/TenantBucketRecordTest.php`

**Interfaces:**
- Produces: `TenantResourceGuard::bucketNameFor(string $uuid): string`; `tenants.storage_disk`, `tenants.storage_bucket`; config `tenancy.storage.{bucket_prefix,local_root,bucket_managers}`; disk `minio`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Storage/TenantBucketRecordTest.php`:
```php
<?php

namespace Tests\Feature\Storage;

use App\Tenancy\TenantResourceGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TenantBucketRecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provisioning_records_the_disk_and_bucket(): void
    {
        $tenant = $this->createTenant('acme');

        $this->assertSame('local', $tenant->storage_disk);
        $this->assertSame('tenanceytest-'.$tenant->uuid, $tenant->storage_bucket);
    }

    public function test_bucket_names_need_a_valid_prefix(): void
    {
        config()->set('tenancy.storage.bucket_prefix', 'Bad_Prefix');

        $this->expectException(InvalidArgumentException::class);

        TenantResourceGuard::bucketNameFor('0199c3a5-0000-7000-8000-000000000000');
    }

    public function test_the_guard_refuses_a_bucket_that_is_not_exactly_the_tenants(): void
    {
        $tenant = $this->createTenant('acme');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_bucket' => 'tenanceytest-someone-else']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage_bucket');

        TenantResourceGuard::assertOwned($tenant->fresh());
    }

    public function test_the_guard_refuses_an_unknown_disk(): void
    {
        $tenant = $this->createTenant('acme');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_disk' => 'ftp']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage_disk');

        TenantResourceGuard::assertOwned($tenant->fresh());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Storage/TenantBucketRecordTest.php`
Expected: FAIL (unknown column `storage_disk` / undefined method `bucketNameFor`).

- [ ] **Step 3: Config**

`config/filesystems.php`, in `local`: `'serve' => false,` (Laravel's built-in local serve route doesn't initialize tenancy; Task 7 adds a tenant route). After `s3`, add:
```php
        // MinIO speaks the S3 protocol: same driver, our own endpoint, buckets as paths.
        'minio' => [
            'driver' => 's3',
            'key' => env('MINIO_KEY'),
            'secret' => env('MINIO_SECRET'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET'), // central default; each tenant gets its own (TenantBucketBootstrapper)
            'endpoint' => env('MINIO_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ],
```
and set `'throw' => true` on `s3`.

`config/tenancy.php`, after the `filesystem` section:
```php
    /**
     * One storage bucket per tenant on the tenant's recorded disk (tenants.storage_disk):
     * a real bucket on S3/MinIO, a folder under local_root on `local`.
     * See docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md.
     */
    'storage' => [
        'bucket_prefix' => env('TENANT_BUCKET_PREFIX', 'tenancey-'),
        'local_root' => storage_path('app/buckets'), // read before tenancy: the central storage path
        'bucket_managers' => [ // keyed by the disk's DRIVER
            'local' => \App\Tenancy\Storage\LocalBucketManager::class,
            's3' => \App\Tenancy\Storage\S3BucketManager::class,
        ],
    ],
```
(Use `use` imports at the top of the file like the existing ones instead of FQCNs.)

`phpunit.xml`, after `TENANT_STORAGE_PREFIX`:
```xml
        <env name="FILESYSTEM_DISK" value="local"/>
        <env name="TENANT_BUCKET_PREFIX" value="tenanceytest-"/>
```

`.env.example`, after the `AWS_*` block:
```
# Local MinIO (S3-compatible). FILESYSTEM_DISK=minio uses it; production uses s3 + AWS_*.
MINIO_ENDPOINT=http://127.0.0.1:19000
MINIO_KEY=minioadmin
MINIO_SECRET=minioadmin
MINIO_REGION=us-east-1

TENANT_BUCKET_PREFIX=tenancey-
```
Add the same keys to `.env` (local file, not committed). **Leave `FILESYSTEM_DISK=local` in `.env` until Task 10.**

- [ ] **Step 4: Migration**

`database/migrations/2026_10_11_000000_add_storage_bucket_columns_to_tenants_table.php`:
```php
<?php

use App\Tenancy\TenantResourceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Recorded at provisioning: FILESYSTEM_DISK only decides where NEW tenants go.
            $table->string('storage_disk', 32)->nullable()->after('storage_path');
            $table->string('storage_bucket', 63)->nullable()->unique()->after('storage_disk');
        });

        // Existing tenants only ever had local folders. Their bucket folders are created by
        // `php artisan tenants:create-buckets`.
        DB::table('tenants')->whereNull('storage_bucket')->orderBy('id')->each(function (object $tenant) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'storage_disk' => 'local',
                'storage_bucket' => TenantResourceGuard::bucketNameFor($tenant->uuid),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'storage_bucket']);
        });
    }
};
```

- [ ] **Step 5: Guard**

In `TenantResourceGuard::assertOwned()`, add `'storage_bucket' => static::bucketNameFor($tenant->uuid),` to `$expected`, and after the loop:
```php
        if (! is_array(config('filesystems.disks.'.$tenant->storage_disk))) {
            throw new RuntimeException("Refusing to touch tenant [{$tenant->uuid}]: storage_disk [{$tenant->storage_disk}] is not a configured disk.");
        }
```
Add:
```php
    /**
     * The tenant's bucket: a real bucket on S3/MinIO, a folder on `local`. S3 bucket names are
     * global across every AWS account, so the prefix must be unique to this app (and install).
     */
    public static function bucketNameFor(string $uuid): string
    {
        $prefix = (string) config('tenancy.storage.bucket_prefix');
        $name = $prefix.$uuid;

        if (! preg_match('/^[a-z0-9][a-z0-9-]*-$/', $prefix) || strlen($name) > 63) {
            throw new InvalidArgumentException("Invalid tenant bucket prefix [{$prefix}]: lowercase letters, digits and hyphens, ending in a hyphen.");
        }

        return $name;
    }
```
Update the class docblock's first sentence to include "storage bucket".

- [ ] **Step 6: Tenant model, provisioner, central page**

`Tenant::getCustomColumns()`: add `'storage_disk'`, `'storage_bucket'` after `'storage_path'`; add `@property string|null $storage_disk` and `@property string|null $storage_bucket` to the docblock.

`TenantProvisioner::provision()`, after `storage_path`:
```php
            $tenant->storage_disk = config('filesystems.default');
            $tenant->storage_bucket = TenantResourceGuard::bucketNameFor($tenant->uuid);
```

`resources/views/central/tenants/show.blade.php`, after `'Storage' => …`:
```php
                    'Files' => ($tenant->storage_disk ?? '—').' / '.($tenant->storage_bucket ?? '—'),
```

`tests/TestCase.php` `createApplication()`: also refuse unless `tenancy.storage.bucket_prefix` is `tenanceytest-` (extend the existing check and message).

- [ ] **Step 7: Run to verify they pass, then the suite**

Run: `php artisan migrate && php artisan test --compact tests/Feature/Storage/TenantBucketRecordTest.php` → 4 passed.
Run: `php artisan test --compact` → all pass.
Run: `vendor/bin/pint --dirty --format agent`.

- [ ] **Step 8: Hand-off**

```bash
git add config/filesystems.php config/tenancy.php phpunit.xml .env.example app/Tenancy/TenantResourceGuard.php app/Models/Tenant.php app/Services/TenantProvisioner.php resources/views/central/tenants/show.blade.php database/migrations/2026_10_11_000000_add_storage_bucket_columns_to_tenants_table.php tests/TestCase.php tests/Feature/Storage/TenantBucketRecordTest.php
git commit -m "Record each tenant's storage disk and bucket"
```

---

### Task 3: Bucket managers (local and S3)

**Files:**
- Create: `app/Tenancy/Storage/BucketManager.php`, `app/Tenancy/Storage/BucketManagers.php`, `app/Tenancy/Storage/LocalBucketManager.php`, `app/Tenancy/Storage/S3BucketManager.php`, `tests/Feature/Storage/LocalBucketManagerTest.php`, `tests/Feature/Storage/S3BucketManagerTest.php`, `tests/Concerns/UsesMinio.php`
- Modify: `tests/TestCase.php` (local bucket cleanup)

**Interfaces:**
- Consumes: `tenancy.storage.*` config (Task 2).
- Produces: `BucketManager::{exists,create,delete}(string $bucket)`; `BucketManagers::forDisk(string $disk): BucketManager`; `LocalBucketManager::path(string $bucket): string`; trait `Tests\Concerns\UsesMinio` (`skipUnlessMinioIsReachable()`, `deleteTestBucketsOnMinio()`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Storage/LocalBucketManagerTest.php`:
```php
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
```

`tests/Concerns/UsesMinio.php`:
```php
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
    protected function skipUnlessMinioIsReachable(): void
    {
        $endpoint = (string) config('filesystems.disks.minio.endpoint');
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT) ?: 80;

        if (blank(config('filesystems.disks.minio.key')) || ! $host || ! @fsockopen($host, $port, $code, $message, 1)) {
            $this->markTestSkipped("MinIO is not reachable at [{$endpoint}].");
        }
    }

    /**
     * Test buckets only (tenanceytest-*): the Mac's MinIO is shared with other projects.
     */
    protected function deleteTestBucketsOnMinio(): void
    {
        $manager = BucketManagers::forDisk('minio');

        foreach (Storage::disk('minio')->getClient()->listBuckets()['Buckets'] ?? [] as $bucket) {
            if (str_starts_with($bucket['Name'], 'tenanceytest-')) {
                $manager->delete($bucket['Name']);
            }
        }
    }
}
```

`tests/Feature/Storage/S3BucketManagerTest.php`:
```php
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
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Storage/LocalBucketManagerTest.php tests/Feature/Storage/S3BucketManagerTest.php`
Expected: FAIL, class `App\Tenancy\Storage\BucketManagers` not found. (Check MinIO first: `launchctl print gui/$(id -u)/local.minio | grep state` → running, so the minio test fails rather than skips.)

- [ ] **Step 3: Implement**

`app/Tenancy/Storage/BucketManager.php`:
```php
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
```

`app/Tenancy/Storage/BucketManagers.php`:
```php
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
```

`app/Tenancy/Storage/LocalBucketManager.php`:
```php
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
```

`app/Tenancy/Storage/S3BucketManager.php`:
```php
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
```

In `tests/TestCase.php` `dropTestTenantResources()`, after the storage folder loop:
```php
        $bucketRoot = config('tenancy.storage.local_root');
        if (File::isDirectory($bucketRoot)) {
            foreach (File::directories($bucketRoot) as $directory) {
                if (str_starts_with(basename($directory), config('tenancy.storage.bucket_prefix'))) {
                    File::deleteDirectory($directory);
                }
            }
        }
```
and extend the docblock ("…storage folder and local bucket…").

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --compact tests/Feature/Storage/LocalBucketManagerTest.php tests/Feature/Storage/S3BucketManagerTest.php`
Expected: 4 passed (the minio test runs, not skipped). Then stop MinIO (`launchctl bootout gui/$(id -u)/local.minio`), rerun: the minio test is **skipped**, the rest pass; start it again (`launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/local.minio.plist`).

- [ ] **Step 5: Suite, Pint, hand-off**

Run: `php artisan test --compact` → all pass. `vendor/bin/pint --dirty --format agent`.
```bash
git add app/Tenancy/Storage tests/Concerns tests/Feature/Storage tests/TestCase.php
git commit -m "Add local and S3 bucket managers"
```

---

### Task 4: Provisioning and teardown steps

**Files:**
- Create: `app/Jobs/Provisioning/CreateTenantBucket.php`, `app/Jobs/Teardown/DeleteTenantBucket.php`, `tests/Feature/Storage/TenantBucketLifecycleTest.php`
- Modify: `app/Services/TenantProvisioner.php` (`STEPS`), `app/Services/TenantTeardown.php` (`STEPS`)

**Interfaces:**
- Consumes: `BucketManagers::forDisk()`, `TenantResourceGuard::assertOwned()`.
- Produces: steps labelled `Create storage bucket`, `Delete storage bucket`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Storage/TenantBucketLifecycleTest.php`:
```php
<?php

namespace Tests\Feature\Storage;

use App\Jobs\Provisioning\CreateTenantBucket;
use App\Jobs\Teardown\DeleteTenantBucket;
use App\Services\TenantTeardown;
use App\Tenancy\Storage\BucketManagers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class TenantBucketLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provisioning_creates_the_bucket_and_teardown_deletes_it(): void
    {
        $tenant = $this->createTenant('acme');
        $manager = BucketManagers::forDisk('local');

        $this->assertTrue($manager->exists($tenant->storage_bucket));

        app(TenantTeardown::class)->teardown($tenant);

        $this->assertFalse($manager->exists($tenant->storage_bucket));
    }

    public function test_both_steps_can_run_again(): void
    {
        $tenant = $this->createTenant('acme');

        (new CreateTenantBucket($tenant->id))->handle(); // exists: skips
        $this->assertTrue(BucketManagers::forDisk('local')->exists($tenant->storage_bucket));

        (new DeleteTenantBucket($tenant->id))->handle();
        (new DeleteTenantBucket($tenant->id))->handle(); // gone: nothing to do (half-provisioned teardown)
        $this->assertFalse(BucketManagers::forDisk('local')->exists($tenant->storage_bucket));
    }

    public function test_delete_refuses_a_bucket_that_is_not_exactly_the_tenants(): void
    {
        $tenant = $this->createTenant('acme');
        $other = $this->createTenant('beta');
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_bucket' => $other->storage_bucket]);

        try {
            (new DeleteTenantBucket($tenant->id))->handle();
            $this->fail('Expected the guard to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to touch tenant', $e->getMessage());
        }

        $this->assertTrue(BucketManagers::forDisk('local')->exists($other->storage_bucket));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Storage/TenantBucketLifecycleTest.php`
Expected: FAIL (bucket not created / class `CreateTenantBucket` not found).

- [ ] **Step 3: Implement**

`app/Jobs/Provisioning/CreateTenantBucket.php`:
```php
<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\TenantResourceGuard;

/**
 * The tenant's file bucket on its recorded disk: a real bucket on S3/MinIO, a folder on local.
 */
class CreateTenantBucket extends TenantJob
{
    public static function label(): string
    {
        return 'Create storage bucket';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        $manager = BucketManagers::forDisk($tenant->storage_disk);

        if ($manager->exists($tenant->storage_bucket)) {
            $this->skip("bucket [{$tenant->storage_bucket}] already exists");

            return;
        }

        $manager->create($tenant->storage_bucket);
    }
}
```

`app/Jobs/Teardown/DeleteTenantBucket.php`:
```php
<?php

namespace App\Jobs\Teardown;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\TenantResourceGuard;

/**
 * Empties and deletes the tenant's bucket. Runs after the tenant database (and its `media` rows)
 * is gone, so it never relies on them: the bucket manager lists what is actually stored.
 */
class DeleteTenantBucket extends TenantJob
{
    public static function label(): string
    {
        return 'Delete storage bucket';
    }

    protected function process(Tenant $tenant): void
    {
        TenantResourceGuard::assertOwned($tenant);

        $manager = BucketManagers::forDisk($tenant->storage_disk);

        if (! $manager->exists($tenant->storage_bucket)) {
            $this->skip("bucket [{$tenant->storage_bucket}] does not exist");

            return;
        }

        $manager->delete($tenant->storage_bucket);
    }
}
```

`TenantProvisioner::STEPS`: add `CreateTenantBucket::class` after `PrepareTenantStorage::class` (with its `use`).
`TenantTeardown::STEPS`: add `DeleteTenantBucket::class` after `DeleteTenantStorage::class` (with its `use`).

- [ ] **Step 4: Run to verify they pass, then the suite**

Run: `php artisan test --compact tests/Feature/Storage/TenantBucketLifecycleTest.php` → 3 passed.
Run: `php artisan test --compact` → all pass (`TenantProgressBroadcastTest` and `TenantManagementTest` read `STEPS`, so they follow).

- [ ] **Step 5: Pint, hand-off**

`vendor/bin/pint --dirty --format agent`.
```bash
git add app/Jobs app/Services tests/Feature/Storage/TenantBucketLifecycleTest.php
git commit -m "Create and delete each tenant's bucket in the chains"
```
Tell the developer: Horizon runs job code, so after committing: `php artisan horizon:terminate`.

---

### Task 5: TenantBucketBootstrapper

**Files:**
- Create: `app/Tenancy/TenantBucketBootstrapper.php`, `tests/Feature/Storage/TenantBucketBootstrapperTest.php`
- Modify: `config/tenancy.php` (bootstrappers; `filesystem.disks` and `root_override` without `local`)

**Interfaces:**
- Consumes: `tenants.storage_disk/storage_bucket`, `tenancy.storage.local_root`.
- Produces: while tenancy is active, the tenant's disk is the default disk and medialibrary's disk, pointing at the tenant's bucket. Task 7 extends `bootstrap()` with local temporary URLs.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Storage/TenantBucketBootstrapperTest.php`:
```php
<?php

namespace Tests\Feature\Storage;

use App\Enums\TenantState;
use App\Models\Tenant;
use App\Tenancy\TenantBucketBootstrapper;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TenantBucketBootstrapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_each_tenant_writes_to_its_own_bucket_and_central_is_restored(): void
    {
        $acme = $this->createTenant('acme');
        $beta = $this->createTenant('beta');
        $centralRoot = config('filesystems.disks.local.root');

        tenancy()->initialize($acme);
        $this->assertSame('local', config('media-library.disk_name'));
        Storage::put('probe.txt', 'acme');

        tenancy()->initialize($beta); // a worker switching tenants without ending first
        $this->assertFalse(Storage::exists('probe.txt'));
        Storage::put('probe.txt', 'beta');

        tenancy()->end();

        $this->assertSame($centralRoot, config('filesystems.disks.local.root'));
        $this->assertFalse(Storage::exists('probe.txt'));
        $this->assertSame('acme', file_get_contents(storage_path("app/buckets/{$acme->storage_bucket}/probe.txt")));
        $this->assertSame('beta', file_get_contents(storage_path("app/buckets/{$beta->storage_bucket}/probe.txt")));
    }

    public function test_a_tenant_without_a_recorded_bucket_is_refused(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'state' => TenantState::Ready,
            'db_name' => 'tenanceytest_x',
            'storage_path' => 'storage/testtenant_x',
            'cache_prefix' => 'tenanceytest_x:',
        ]);

        $this->expectException(RuntimeException::class);

        app(TenantBucketBootstrapper::class)->bootstrap($tenant);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Storage/TenantBucketBootstrapperTest.php`
Expected: FAIL (file written to the tenant's stancl folder, not the bucket; class not found).

- [ ] **Step 3: Implement**

`app/Tenancy/TenantBucketBootstrapper.php`:
```php
<?php

namespace App\Tenancy;

use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\Storage\LocalBucketManager;
use Illuminate\Support\Facades\Storage;
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
```

`config/tenancy.php`: add `App\Tenancy\TenantBucketBootstrapper::class` (imported) right after `FilesystemTenancyBootstrapper::class`; in `filesystem.disks` remove `'local'` (leave `'public'`, `// 's3'`), and in `root_override` remove the `'local'` line. Add a comment above `disks`: `// 'local' is TenantBucketBootstrapper's (one bucket per tenant on every driver).`

- [ ] **Step 4: Run to verify they pass, then the suite**

Run: `php artisan test --compact tests/Feature/Storage/TenantBucketBootstrapperTest.php` → 2 passed.
Run: `php artisan test --compact` → all pass.

- [ ] **Step 5: Pint, hand-off**

```bash
git add app/Tenancy/TenantBucketBootstrapper.php config/tenancy.php tests/Feature/Storage/TenantBucketBootstrapperTest.php
git commit -m "Point each tenant's disk at its own bucket"
```
Then `php artisan horizon:terminate` (worker code).

---

### Task 6: `tenants:create-buckets` for existing tenants

**Files:**
- Create: `app/Console/Commands/CreateTenantBuckets.php`, `tests/Feature/Storage/CreateTenantBucketsCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Storage;

use App\Services\TenantTeardown;
use App\Tenancy\Storage\BucketManagers;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CreateTenantBucketsCommandTest extends TestCase
{
    public function test_it_creates_missing_buckets_for_live_tenants_only(): void
    {
        Mail::fake();
        $acme = $this->createTenant('acme');
        $gone = $this->createTenant('gone');
        app(TenantTeardown::class)->teardown($gone);
        $manager = BucketManagers::forDisk('local');
        $manager->delete($acme->storage_bucket); // as for a tenant made before buckets existed

        $this->artisan('tenants:create-buckets')->assertSuccessful();
        $this->artisan('tenants:create-buckets')->assertSuccessful();

        $this->assertTrue($manager->exists($acme->storage_bucket));
        $this->assertFalse($manager->exists($gone->storage_bucket));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Storage/CreateTenantBucketsCommandTest.php`
Expected: FAIL, command `tenants:create-buckets` is not defined.

- [ ] **Step 3: Implement**

Run: `php artisan make:command CreateTenantBuckets --no-interaction`, then:
```php
<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\TenantResourceGuard;
use Illuminate\Console\Command;

class CreateTenantBuckets extends Command
{
    protected $signature = 'tenants:create-buckets';

    protected $description = "Create the storage bucket of every live tenant that doesn't have one yet (safe to re-run)";

    public function handle(): int
    {
        Tenant::query()->whereNotNull('storage_bucket')->orderBy('id')->each(function (Tenant $tenant) {
            TenantResourceGuard::assertOwned($tenant);

            BucketManagers::forDisk($tenant->storage_disk)->create($tenant->storage_bucket);

            $this->components->info("{$tenant->subdomain}: {$tenant->storage_disk} / {$tenant->storage_bucket}");
        });

        return self::SUCCESS;
    }
}
```
(Replace the generated file's body with this; keep the generated style if it differs only in formatting.)

- [ ] **Step 4: Run to verify it passes; suite; Pint**

Run the test → 1 passed. `php artisan test --compact` → all pass. Pint.

- [ ] **Step 5: Run it for the real Valet tenants, hand-off**

Run: `php artisan tenants:create-buckets` → one line per existing tenant, `local / tenancey-…`.
```bash
git add app/Console/Commands/CreateTenantBuckets.php tests/Feature/Storage/CreateTenantBucketsCommandTest.php
git commit -m "Add tenants:create-buckets for tenants made before buckets"
```

---

### Task 7: Media on users, and signed local downloads

**Files:**
- Create: `app/Http/Controllers/Tenant/LocalFileController.php`, `tests/Feature/Storage/LocalSignedUrlTest.php`
- Modify: `app/Models/User.php`, `app/Tenancy/TenantBucketBootstrapper.php`, `routes/tenant.php`

**Interfaces:**
- Produces: route `tenant.storage.local` (`GET /storage/local/{path}`, `signed:relative`); `User` `avatar` and `files` collections, `thumb` conversion; `Storage::temporaryUrl()` and `$media->getTemporaryUrl()` work on `local` inside tenancy.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Storage/LocalSignedUrlTest.php`:
```php
<?php

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalSignedUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_local_temporary_url_serves_the_tenants_file_until_it_expires(): void
    {
        $tenant = $this->createTenant('acme');

        tenancy()->initialize($tenant);
        Storage::put('docs/report.txt', 'hello');
        $url = Storage::temporaryUrl('docs/report.txt', now()->addMinutes(5));
        tenancy()->end();

        $this->assertStringStartsWith($this->tenantUrl('acme', '/storage/local/docs/report.txt'), $url);

        $response = $this->get($url)->assertOk()->assertDownload('report.txt');
        $this->assertSame('hello', $response->streamedContent());

        $this->get(str_replace('report.txt', 'other.txt', $url))->assertForbidden(); // tampered

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden(); // expired
    }

    public function test_a_signed_url_for_one_tenant_does_not_work_on_another(): void
    {
        $acme = $this->createTenant('acme');
        $this->createTenant('beta');

        tenancy()->initialize($acme);
        Storage::put('secret.txt', 'acme only');
        $url = Storage::temporaryUrl('secret.txt', now()->addMinutes(5));
        tenancy()->end();

        $this->get(str_replace('acme.', 'beta.', $url))->assertNotFound();
    }
}
```

Note on the second test: the signature is relative (path + query, not host), so on `beta` it is *valid*, and the controller must answer 404 because beta's bucket has no `secret.txt`. That is the tenant isolation this test pins.

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Storage/LocalSignedUrlTest.php`
Expected: FAIL, "This driver does not support creating temporary URLs."

- [ ] **Step 3: Implement**

`routes/tenant.php`, inside the tenant group, after the `impersonate/{token}` route (outside `auth`: the link itself is the permission, as for S3):
```php
    // Temporary links to files on the `local` disk (TenantBucketBootstrapper signs them).
    Route::get('storage/local/{path}', LocalFileController::class)
        ->middleware('signed:relative')
        ->where('path', '.*')
        ->name('tenant.storage.local');
```

`app/Http/Controllers/Tenant/LocalFileController.php`:
```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a signed temporary link on the `local` disk: the local stand-in for an S3 presigned URL.
 * Tenancy is initialized, so the disk's root is this tenant's bucket folder.
 */
class LocalFileController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        $disk = Storage::disk(tenant('storage_disk'));

        abort_unless(config('filesystems.disks.'.tenant('storage_disk').'.driver') === 'local' && $disk->exists($path), 404);

        return $disk->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff']);
    }
}
```

`TenantBucketBootstrapper::bootstrap()`, after `Storage::forgetDisk($disk);`:
```php
        if ($manager instanceof LocalBucketManager) {
            // S3/MinIO sign their own links. Local ones go through a tenant route, signed relative
            // (path + query) and made absolute with the tenant's own host (Tenant::url()).
            Storage::disk($disk)->buildTemporaryUrlsUsing(
                fn (string $path, DateTimeInterface $expiration) => $tenant->url(
                    URL::temporarySignedRoute('tenant.storage.local', $expiration, ['path' => $path], absolute: false),
                ),
            );
        }
```
(imports: `DateTimeInterface`, `Illuminate\Support\Facades\URL`).

`app/Models/User.php`: `implements HasMedia`, `use InteractsWithMedia` (with `HasFactory, HasRoles, Notifiable`), and:
```php
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('files');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Made right away (not queued): the page and the tests never wait for Horizon.
        $this->addMediaConversion('thumb')
            ->performOnCollections('avatar')
            ->fit(Fit::Crop, 128, 128)
            ->nonQueued();
    }
```
(imports: `Spatie\Image\Enums\Fit`, `Spatie\MediaLibrary\HasMedia`, `Spatie\MediaLibrary\InteractsWithMedia`, `Spatie\MediaLibrary\MediaCollections\Models\Media`).

- [ ] **Step 4: Run to verify they pass, then the suite**

Run: `php artisan test --compact tests/Feature/Storage/LocalSignedUrlTest.php` → 2 passed.
Run: `php artisan test --compact` → all pass (new tenants now also get the `media` table).

- [ ] **Step 5: Pint, hand-off**

```bash
git add app/Http/Controllers/Tenant/LocalFileController.php app/Models/User.php app/Tenancy/TenantBucketBootstrapper.php routes/tenant.php tests/Feature/Storage/LocalSignedUrlTest.php
git commit -m "Give users media collections and sign local downloads"
```
Then for existing tenants: `php artisan tenants:migrate` (adds `media`).

---

### Task 8: Avatar

**Files:**
- Create: `app/Http/Controllers/Tenant/AvatarController.php`, `app/Http/Requests/UpdateAvatarRequest.php`, `resources/views/tenant/profile/edit.blade.php`, `tests/Feature/Tenant/AvatarTest.php`, `tests/Concerns/SignsInTenantUsers.php`
- Modify: `routes/tenant.php`, `resources/views/components/layouts/tenant.blade.php`

**Interfaces:**
- Produces: routes `profile` (GET), `profile.avatar` (PUT, DELETE); trait `signInAs(string $subdomain): User`.

- [ ] **Step 1: Write the failing tests**

`tests/Concerns/SignsInTenantUsers.php`:
```php
<?php

namespace Tests\Concerns;

use App\Models\User;

trait SignsInTenantUsers
{
    /**
     * Provision a tenant and sign in as its first user (password already changed).
     */
    protected function signInAs(string $subdomain = 'acme'): User
    {
        $tenant = $this->createTenant($subdomain);

        $user = $tenant->run(function () {
            $user = User::query()->firstOrFail();
            $user->forceFill(['must_change_password' => false])->save();

            return $user;
        });

        auth()->shouldUse('web');
        $this->actingAs($user, 'web');

        return $user;
    }
}
```

`tests/Feature/Tenant/AvatarTest.php`:
```php
<?php

namespace Tests\Feature\Tenant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsInTenantUsers;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use SignsInTenantUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_uploading_an_avatar_stores_it_with_a_thumbnail_in_the_tenants_bucket(): void
    {
        $user = $this->signInAs('acme');

        $this->put($this->tenantUrl('acme', '/profile/avatar'), ['avatar' => UploadedFile::fake()->image('me.png', 300, 300)])
            ->assertRedirect($this->tenantUrl('acme', '/profile'));

        $media = $this->tenant('acme')->run(fn () => $user->fresh()->getFirstMedia('avatar'));
        $this->assertNotNull($media);
        $this->assertTrue($media->hasGeneratedConversion('thumb'));
        $bucket = storage_path('app/buckets/'.$this->tenant('acme')->storage_bucket);
        $this->assertFileExists($bucket.'/'.$media->getPathRelativeToRoot('thumb'));

        $this->get($this->tenantUrl('acme', '/profile'))->assertOk()->assertSee('/storage/local/', false);
    }

    public function test_a_new_avatar_replaces_the_old_one_and_can_be_removed(): void
    {
        $user = $this->signInAs('acme');
        $url = $this->tenantUrl('acme', '/profile/avatar');

        $this->put($url, ['avatar' => UploadedFile::fake()->image('one.png')]);
        $this->put($url, ['avatar' => UploadedFile::fake()->image('two.png')]);
        $this->assertSame(['two.png'], $this->tenant('acme')->run(fn () => $user->fresh()->getMedia('avatar')->pluck('file_name')->all()));

        $this->delete($url)->assertRedirect($this->tenantUrl('acme', '/profile'));
        $this->assertSame(0, $this->tenant('acme')->run(fn () => $user->fresh()->getMedia('avatar')->count()));
    }

    public function test_only_small_images_are_accepted(): void
    {
        $this->signInAs('acme');
        $url = $this->tenantUrl('acme', '/profile/avatar');

        $this->put($url, ['avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('avatar');
        $this->put($url, ['avatar' => UploadedFile::fake()->image('big.png')->size(3000)])->assertSessionHasErrors('avatar');
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenant/AvatarTest.php`
Expected: FAIL, 404 on `/profile/avatar`.

- [ ] **Step 3: Implement**

`app/Http/Requests/UpdateAvatarRequest.php` (via `php artisan make:request UpdateAvatarRequest --no-interaction`):
```php
    public function authorize(): bool
    {
        return true; // the route is behind auth: a user only ever changes their own avatar
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ];
    }
```

`app/Http/Controllers/Tenant/AvatarController.php`:
```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AvatarController extends Controller
{
    public function edit(Request $request): View
    {
        return view('tenant.profile.edit', ['user' => $request->user()]);
    }

    public function update(UpdateAvatarRequest $request): RedirectResponse
    {
        $media = $request->user()->addMediaFromRequest('avatar')->toMediaCollection('avatar');

        Log::channel('tenancy')->info('Avatar uploaded', [...tenant()->logContext(), 'user_id' => $request->user()->id, 'media_id' => $media->id]);

        return redirect()->route('profile')->with('status', 'Avatar updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->clearMediaCollection('avatar');

        Log::channel('tenancy')->info('Avatar removed', [...tenant()->logContext(), 'user_id' => $request->user()->id]);

        return redirect()->route('profile')->with('status', 'Avatar removed.');
    }
}
```

`routes/tenant.php`, inside `auth`, after `dashboard`:
```php
        Route::middleware(EnsurePasswordChanged::class)->group(function () {
            Route::get('profile', [AvatarController::class, 'edit'])->name('profile');
            Route::put('profile/avatar', [AvatarController::class, 'update'])->name('profile.avatar');
            Route::delete('profile/avatar', [AvatarController::class, 'destroy']);
        });
```

`resources/views/tenant/profile/edit.blade.php`:
```blade
<x-layouts.tenant title="Profile">
    <h1 class="text-2xl font-semibold">Profile</h1>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="font-semibold">Avatar</h2>
        <div class="mt-4 flex items-center gap-6">
            @if ($thumb = $user->getFirstTemporaryUrl(now()->addMinutes(5), 'avatar', 'thumb'))
                <img src="{{ $thumb }}" alt="Your avatar" class="h-16 w-16 rounded-full object-cover">
            @else
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-indigo-100 text-xl font-semibold text-indigo-700">{{ mb_substr($user->name, 0, 1) }}</div>
            @endif

            <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                @csrf
                @method('PUT')
                <x-field name="avatar" label="New avatar" type="file" accept="image/jpeg,image/png,image/webp" hint="JPEG, PNG or WebP, up to 2 MB." required />
                <x-button>Upload</x-button>
            </form>
        </div>

        @if ($user->getFirstMedia('avatar'))
            <form method="POST" action="{{ route('profile.avatar') }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-button variant="secondary">Remove avatar</x-button>
            </form>
        @endif
    </section>
</x-layouts.tenant>
```

`resources/views/components/layouts/tenant.blade.php`, in the `@auth` nav, before the email span:
```blade
                    <a href="{{ route('profile') }}" class="text-gray-600 hover:underline">Profile</a>
```
(Task 9 adds "My files" beside it.)

- [ ] **Step 4: Run to verify they pass, then the suite**

Run: `php artisan test --compact tests/Feature/Tenant/AvatarTest.php` → 3 passed. `php artisan test --compact` → all pass. Pint.

- [ ] **Step 5: Hand-off**

```bash
git add app/Http/Controllers/Tenant/AvatarController.php app/Http/Requests/UpdateAvatarRequest.php resources/views/tenant/profile resources/views/components/layouts/tenant.blade.php routes/tenant.php tests/Concerns/SignsInTenantUsers.php tests/Feature/Tenant/AvatarTest.php
git commit -m "Let tenant users upload an avatar"
```

---

### Task 9: My files

**Files:**
- Create: `app/Http/Controllers/Tenant/FileController.php`, `app/Http/Requests/StoreFileRequest.php`, `resources/views/tenant/files/index.blade.php`, `tests/Feature/Tenant/MyFilesTest.php`
- Modify: `routes/tenant.php`, `resources/views/components/layouts/tenant.blade.php`

**Interfaces:**
- Produces: routes `files.index` (GET `/files`), `files.store` (POST), `files.download` (GET `/files/{media}/download`), `files.destroy` (DELETE `/files/{media}`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Tenant/MyFilesTest.php`:
```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsInTenantUsers;
use Tests\TestCase;

class MyFilesTest extends TestCase
{
    use SignsInTenantUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_user_uploads_lists_downloads_and_deletes_a_file(): void
    {
        $user = $this->signInAs('acme');

        $this->post($this->tenantUrl('acme', '/files'), ['file' => UploadedFile::fake()->createWithContent('report.txt', 'quarterly')])
            ->assertRedirect($this->tenantUrl('acme', '/files'));

        $media = $this->tenant('acme')->run(fn () => $user->fresh()->getFirstMedia('files'));
        $this->get($this->tenantUrl('acme', '/files'))->assertOk()->assertSee('report.txt');

        $link = $this->get($this->tenantUrl('acme', "/files/{$media->id}/download"))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/storage/local/', $link);
        $this->assertSame('quarterly', $this->get($link)->assertDownload('report.txt')->streamedContent());

        $this->delete($this->tenantUrl('acme', "/files/{$media->id}"))->assertRedirect($this->tenantUrl('acme', '/files'));
        $this->assertSame(0, $this->tenant('acme')->run(fn () => $user->fresh()->getMedia('files')->count()));
        $this->assertFileDoesNotExist(storage_path('app/buckets/'.$this->tenant('acme')->storage_bucket.'/'.$media->getPathRelativeToRoot()));
    }

    public function test_another_users_file_is_not_found(): void
    {
        $this->signInAs('acme');
        $bob = $this->tenant('acme')->run(function () {
            $bob = User::factory()->create();
            $bob->addMedia(UploadedFile::fake()->createWithContent('bob.txt', 'private'))->toMediaCollection('files');

            return $bob;
        });
        $mediaId = $this->tenant('acme')->run(fn () => $bob->getFirstMedia('files')->id);

        $this->get($this->tenantUrl('acme', "/files/{$mediaId}/download"))->assertNotFound();
        $this->delete($this->tenantUrl('acme', "/files/{$mediaId}"))->assertNotFound();
        $this->get($this->tenantUrl('acme', '/files'))->assertDontSee('bob.txt');
    }

    public function test_files_over_10_mb_are_rejected(): void
    {
        $this->signInAs('acme');

        $this->post($this->tenantUrl('acme', '/files'), ['file' => UploadedFile::fake()->create('huge.bin', 10241)])
            ->assertSessionHasErrors('file');
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Tenant/MyFilesTest.php`
Expected: FAIL, 404 on `/files`.

- [ ] **Step 3: Implement**

`app/Http/Requests/StoreFileRequest.php` (via `make:request`): `authorize()` returns true (same comment as the avatar); rules `'file' => ['required', 'file', 'max:10240']`.

`app/Http/Controllers/Tenant/FileController.php`:
```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * "My files": each user sees and manages only their own uploads. Downloads are 5-minute signed
 * links straight to the tenant's bucket (S3/MinIO) or to the local file route.
 */
class FileController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.files.index', ['files' => $request->user()->getMedia('files')]);
    }

    public function store(StoreFileRequest $request): RedirectResponse
    {
        $media = $request->user()->addMediaFromRequest('file')->toMediaCollection('files');

        Log::channel('tenancy')->info('File uploaded', [...tenant()->logContext(), 'user_id' => $request->user()->id, 'media_id' => $media->id]);

        return redirect()->route('files.index')->with('status', "Uploaded {$media->file_name}.");
    }

    public function download(Request $request, Media $media): RedirectResponse
    {
        $this->ensureOwnedBy($request, $media);

        return redirect()->away($media->getTemporaryUrl(now()->addMinutes(5), '', [
            'ResponseContentDisposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
        ]));
    }

    public function destroy(Request $request, Media $media): RedirectResponse
    {
        $this->ensureOwnedBy($request, $media);

        $media->delete();

        Log::channel('tenancy')->info('File deleted', [...tenant()->logContext(), 'user_id' => $request->user()->id, 'media_id' => $media->id]);

        return redirect()->route('files.index')->with('status', "Deleted {$media->file_name}.");
    }

    /**
     * Someone else's file is "not found", not "forbidden": its existence isn't revealed.
     */
    protected function ensureOwnedBy(Request $request, Media $media): void
    {
        abort_unless(
            $media->collection_name === 'files'
                && $media->model_type === $request->user()->getMorphClass()
                && (int) $media->model_id === $request->user()->id,
            404,
        );
    }
}
```

`routes/tenant.php`, inside the `EnsurePasswordChanged` group from Task 8:
```php
            Route::get('files', [FileController::class, 'index'])->name('files.index');
            Route::post('files', [FileController::class, 'store'])->name('files.store');
            Route::get('files/{media}/download', [FileController::class, 'download'])->name('files.download');
            Route::delete('files/{media}', [FileController::class, 'destroy'])->name('files.destroy');
```

`resources/views/tenant/files/index.blade.php`:
```blade
<x-layouts.tenant title="My files">
    <h1 class="text-2xl font-semibold">My files</h1>

    <form method="POST" action="{{ route('files.store') }}" enctype="multipart/form-data" class="mt-6 flex items-end gap-3 rounded-lg border border-gray-200 bg-white p-6">
        @csrf
        <x-field name="file" label="Upload a file" type="file" hint="Any type, up to 10 MB." required />
        <x-button>Upload</x-button>
    </form>

    <div class="mt-6 overflow-hidden rounded-lg border border-gray-200 bg-white">
        @forelse ($files as $file)
            <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-0">
                <div>
                    <a href="{{ route('files.download', $file) }}" class="font-medium text-indigo-700 hover:underline">{{ $file->file_name }}</a>
                    <p class="text-xs text-gray-500">{{ $file->human_readable_size }} · {{ $file->created_at->diffForHumans() }}</p>
                </div>
                <form method="POST" action="{{ route('files.destroy', $file) }}">
                    @csrf
                    @method('DELETE')
                    <x-button variant="secondary">Delete</x-button>
                </form>
            </div>
        @empty
            <p class="px-4 py-6 text-sm text-gray-500">No files yet.</p>
        @endforelse
    </div>
</x-layouts.tenant>
```

Layout nav: add `<a href="{{ route('files.index') }}" class="text-gray-600 hover:underline">My files</a>` before "Profile".

- [ ] **Step 4: Run to verify they pass, then the suite**

Run: `php artisan test --compact tests/Feature/Tenant/MyFilesTest.php` → 3 passed. `php artisan test --compact` → all pass. Pint.

- [ ] **Step 5: Hand-off**

```bash
git add app/Http/Controllers/Tenant/FileController.php app/Http/Requests/StoreFileRequest.php resources/views/tenant/files resources/views/components/layouts/tenant.blade.php routes/tenant.php tests/Feature/Tenant/MyFilesTest.php
git commit -m "Add My files for tenant users"
```

---

### Task 10: Valet on MinIO, end to end

**Files:**
- Create: `tests/Feature/Storage/MinioEndToEndTest.php`
- Modify: `.env` (local only: `FILESYSTEM_DISK=minio`)

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/Storage/MinioEndToEndTest.php`
Expected: PASS if Tasks 3–9 are right (this test pins the real S3 path end to end; the code exists already). If it fails, debug with superpowers:systematic-debugging, don't adjust the test. To see it fail meaningfully once, temporarily set `MINIO_KEY` wrong in `.env`: FAIL with an S3 403; restore it.

- [ ] **Step 3: Switch Valet to MinIO**

In `.env`: `FILESYSTEM_DISK=minio`. Then `php artisan horizon:terminate` (supervisor restarts Horizon with the new setting).

- [ ] **Step 4: Developer checks in the browser**

1. http://tenancey.test → create tenant `globex`. The progress page shows "Create storage bucket".
2. http://localhost:19001 (MinIO dashboard, `minioadmin`): bucket `tenancey-<uuid>` exists.
3. Sign in to globex, Profile → upload an avatar: the thumbnail shows (DevTools → Network: the image comes from `127.0.0.1:19000/tenancey-…?X-Amz-Signature=…`).
4. My files → upload, download (file saves, not opens), delete.
5. MinIO dashboard: the bucket's objects match (`1/…`, `1/conversions/…-thumb.…`).
6. Delete `globex` in the central admin: the bucket disappears from MinIO.
7. An old (local) tenant: Profile → avatar upload still works (its recorded disk is `local`).

- [ ] **Step 5: Full suite, hand-off**

Run: `php artisan test --compact` → all pass, the `minio` group included (MinIO running).
```bash
git add tests/Feature/Storage/MinioEndToEndTest.php
git commit -m "Test tenant storage on MinIO end to end"
```

---

### Task 11: Documentation

**Files:**
- Create: `docs/STORAGE.md`
- Modify: `CLAUDE.md`, `README.md`

TDD exception: documentation only.

- [ ] **Step 1: `docs/STORAGE.md`**

Sections, short and concrete (match `docs/QUEUES.md`'s tone):
1. **One setting:** `FILESYSTEM_DISK` = `local` | `minio` | `s3`; what each reads (`MINIO_*`, `AWS_*`); production = set `AWS_*` and `FILESYSTEM_DISK=s3`.
2. **One bucket per tenant:** name rule, `TENANT_BUCKET_PREFIX`, local folders in `storage/app/buckets/`, recorded `storage_disk`/`storage_bucket` (new tenants only follow `FILESYSTEM_DISK`), `tenants:create-buckets`.
3. **The pieces:** `BucketManager` (local, S3), `CreateTenantBucket` / `DeleteTenantBucket`, `TenantResourceGuard`, `TenantBucketBootstrapper` (what it sets and restores) vs stancl's `FilesystemTenancyBootstrapper` (only `storage_path()` and `public` now).
4. **Files in the app:** medialibrary on `User` (`avatar` + `thumb`, `files`), the `media` table per tenant, 5-minute signed links, the local signed route, ownership 404.
5. **MinIO on the Mac:** launch agent `local.minio`, ports 19000 (API) / 19001 (dashboard), `launchctl` commands, why not `brew services` (port 9000 vs PHP-FPM).
6. **Tests:** local buckets with `tenanceytest-`, the `minio` group (skipped when MinIO is down; run alone with `php artisan test --group=minio`).
7. **Production:** the IAM policy (spec §10), unique prefix, bucket limit, Block Public Access is fine.

- [ ] **Step 2: `CLAUDE.md`**

- Reference docs: add `docs/STORAGE.md` ("read before changing storage, buckets or medialibrary").
- Multi-tenancy: a **Storage** bullet: one bucket per tenant on the recorded `storage_disk`; created/deleted only through `BucketManager` behind `TenantResourceGuard`; never delete buckets by pattern (the Mac's MinIO is shared); `FILESYSTEM_DISK` affects new tenants only.
- Environment: MinIO at `127.0.0.1:19000` (dashboard 19001), launch agent `local.minio`.
- Testing: `tenanceytest-` buckets; `#[Group('minio')]` needs MinIO and is skipped without it.

- [ ] **Step 3: `README.md`**

A short "MinIO (file storage)" section in the Valet setup: install (`brew install minio`), don't use `brew services` for it, the launch agent (link `docs/STORAGE.md`), `.env` keys, `php artisan tenants:create-buckets` after first enabling.

- [ ] **Step 4: Verify and hand-off**

Read each changed section once; check every command and path exists (`grep -n` the class names). Suite unchanged.
```bash
git add docs/STORAGE.md CLAUDE.md README.md
git commit -m "Document tenant storage buckets and MinIO"
```

---

## Self-review notes

- Spec coverage: §3 config → Tasks 1–2; §4 buckets/guard → 2–3; §5 row/bootstrapper → 2, 5, 6; §6 steps + local signed downloads → 4, 7; §7 feature → 7–9; §8 tests → every task + 10; §9 Docker → out of scope (phase 2); §10/§11 → 11.
- Review Focus tests: (1) Task 5 two-tenant test; (2) Task 4 `test_both_steps_can_run_again`; (3) Task 2 guard tests + Task 4 refusal; (4) Task 7 tampered/expired/cross-tenant + Task 9 other-user 404; (5) Task 3 minio non-empty delete.
