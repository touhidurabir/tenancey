# Tenant storage buckets (local, MinIO, S3) with medialibrary: design

Status: approved in conversation 2026-10-11, pending review of this document.

## 1. Goal

One setting, `FILESYSTEM_DISK`, chooses where tenant files live: `local`, `minio` or `s3`.
Development uses MinIO (S3-compatible); production switches to AWS S3 by changing `.env` only.
A small tenant-side feature (avatar + "My files", via spatie/laravel-medialibrary) proves it.

### Phases
1. **Valet** (this design's implementation plan): the Mac's MinIO at `127.0.0.1:19000`.
2. **Docker** (later, as a staged learning series like the Docker local-dev work): a `minio`
   service. Designed here (§9) so phase 1 doesn't paint it into a corner, not built yet.
3. **Future direction, not planned:** run the app in Docker only and drop Valet.

### Non-goals
- Moving existing tenants' files between disks.
- Public (unsigned) file URLs, CDN, image galleries.
- Sharing files between tenant users.

## 2. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | `FILESYSTEM_DISK` = `local` \| `minio` \| `s3`; `minio` is its own disk (`MINIO_*` env), `s3` reads `AWS_*` | both configurable at once; production = set `AWS_*`, flip the disk |
| D2 | **One bucket per tenant**, named `TENANT_BUCKET_PREFIX` + uuid | isolation like the per-tenant database |
| D3 | **One path for all drivers**: `TenantBucketBootstrapper` + `CreateTenantBucket` / `DeleteTenantBucket`, through a per-driver `BucketManager`. On `local` a "bucket" is a folder | no local-vs-cloud branches; idempotent steps |
| D4 | The tenant row **records** `storage_disk` and `storage_bucket` at provisioning; runtime and teardown use the recorded values | changing `FILESYSTEM_DISK` affects new tenants only; nothing is orphaned |
| D5 | spatie/laravel-medialibrary; `User` has `avatar` (single image + `thumb`) and `files` | the feature that exercises the storage |
| D6 | Downloads via **temporary signed URLs** (5 minutes); on `local`, a tenant route signs and serves | standard S3 pattern; PHP doesn't carry the bytes on MinIO/S3 |
| D7 | Tests: the suite runs on the `local` driver with test-prefixed buckets (our stand-in for `Storage::fake`, see §8), plus a `minio` group that runs against real MinIO and is **skipped** when unreachable | fast suite with no MinIO needed; real S3 behaviour still covered |
| D8 | Docker image (phase 2): `cgr.dev/chainguard/minio`, pinned by digest | MinIO no longer publishes images or Linux binaries (Docker Hub 404, dl.min.io 410) |

## 3. Configuration

### `config/filesystems.php`
- `default` => `env('FILESYSTEM_DISK', 'local')` (unchanged).
- `local` disk: `serve` => **false** (Laravel's built-in `storage.local` route does not initialize
  tenancy; §6 adds a tenant route instead).
- New `minio` disk:
  ```php
  'minio' => [
      'driver' => 's3',
      'key' => env('MINIO_KEY'),
      'secret' => env('MINIO_SECRET'),
      'region' => env('MINIO_REGION', 'us-east-1'),
      'bucket' => env('MINIO_BUCKET'),          // central default; tenants get their own (§4)
      'endpoint' => env('MINIO_ENDPOINT'),       // what PHP connects to
      'use_path_style_endpoint' => true,         // MinIO serves buckets as paths, not subdomains
      'throw' => true,
      'report' => false,
  ],
  ```
- `s3` disk: unchanged keys, `throw` => true.

### `config/tenancy.php`
- `filesystem.disks`: remove `local` (owned by `TenantBucketBootstrapper` now); `public` stays.
  `suffix_storage_path` stays true: tenant logs, framework cache and views remain in
  `storage/tenant_{uuid}/` (`PrepareTenantStorage`, `DeleteTenantStorage` unchanged).
- New `storage` section:
  ```php
  'storage' => [
      'bucket_prefix' => env('TENANT_BUCKET_PREFIX', 'tenancey-'),
      'local_root' => storage_path('app/buckets'),   // evaluated before tenancy: the central path
      'bucket_managers' => [
          'local' => LocalBucketManager::class,
          's3' => S3BucketManager::class,            // keyed by DRIVER: covers minio and s3 disks
      ],
  ],
  ```
- Bootstrappers: add `TenantBucketBootstrapper` after `FilesystemTenancyBootstrapper`.

### `config/media-library.php` (published)
- `disk_name` => `env('FILESYSTEM_DISK', 'local')`; the bootstrapper overrides it per tenant (§5).
- `queue_conversions_by_default` => true stays; the `thumb` conversion is `nonQueued()` (§7).
- `max_file_size` => 10 MB.

### Environment
| Key | Valet (`.env`) | Tests (`phpunit.xml`) | Production |
|---|---|---|---|
| `FILESYSTEM_DISK` | `minio` | `local` | `s3` |
| `TENANT_BUCKET_PREFIX` | `tenancey-` | `tenanceytest-` | globally unique, e.g. `acme-tenancey-` |
| `MINIO_ENDPOINT` | `http://127.0.0.1:19000` | same (minio group only) | — |
| `MINIO_KEY` / `MINIO_SECRET` | `minioadmin` / `minioadmin` | same | — |
| `AWS_*` | empty | empty | real keys, region |

`.env.example` gets the `MINIO_*` and `TENANT_BUCKET_PREFIX` keys (commented defaults).

### Composer
- `"php": "^8.4"` (medialibrary 11.23 requires it; both setups run 8.4).
- Add `spatie/laravel-medialibrary` (^11.23) and `league/flysystem-aws-s3-v3` (^3.0).

## 4. Buckets

### Names
`bucket_prefix` + tenant uuid, e.g. `tenancey-9f2c…` (45 characters; S3 allows 3–63, lowercase
letters, digits, hyphens). `TenantResourceGuard::bucketNameFor($uuid)` is the only place that
builds it. The prefix must end with `-` and match `/^[a-z0-9][a-z0-9-]*-$/` (validated when the
name is built).

### `App\Tenancy\Storage\BucketManager` (interface)
```php
public function exists(string $bucket): bool;
public function create(string $bucket): void;   // idempotent: no-op if it exists and is ours
public function delete(string $bucket): void;   // idempotent: empties, then deletes; no-op if gone
```
Resolved per disk: `BucketManager::forDisk(string $disk)` reads the disk's `driver` and picks the
class from `tenancy.storage.bucket_managers`, passing the disk name (for its config).

- **`LocalBucketManager`**: a bucket is the folder `{local_root}/{bucket}`. `create` =
  `ensureDirectoryExists`; `delete` = `deleteDirectory`.
- **`S3BucketManager`**: builds an `S3Client` from the disk's config.
  - `exists` = `HeadBucket` (200 → true, 404 → false, 403 → throw: exists but not ours).
  - `create` = `CreateBucket`; `BucketAlreadyOwnedByYou` → success (retry);
    `BucketAlreadyExists` (someone else's, possible on AWS) → **throw**, provisioning fails.
  - `delete` = list and delete every object version and delete marker (batches of 1000), then
    `DeleteBucket`; `NoSuchBucket` at any point → success.

### Guard
`TenantResourceGuard::assertOwned()` also checks `storage_bucket === bucketNameFor(uuid)` and
that `storage_disk` is a configured disk. Every bucket create/delete goes through it.

## 5. Tenant row and bootstrapper

### Migration (central)
`tenants.storage_disk` (string 32) and `tenants.storage_bucket` (string 63, unique). Existing
rows: backfill `storage_disk = 'local'`, `storage_bucket = bucketNameFor(uuid)`; their bucket
folder is created by a one-off `tenants:create-buckets` command (idempotent; uses the same
manager). Added to `Tenant::getCustomColumns()`; shown on the central tenant page next to
Storage.

### `TenantProvisioner`
Sets `storage_disk = config('filesystems.default')` and `storage_bucket = bucketNameFor(uuid)`
when saving the row.

### `App\Tenancy\TenantBucketBootstrapper`
- `bootstrap($tenant)`: remember originals, then for the tenant's `storage_disk`:
  - S3-driver disk: `filesystems.disks.{disk}.bucket` = `storage_bucket`.
  - local-driver disk: `filesystems.disks.{disk}.root` = `{local_root}/{storage_bucket}`.
  - `filesystems.default` = `storage_disk`; `media-library.disk_name` = `storage_disk`.
  - `Storage::forgetDisk($disk)` so the next call builds it with the new config.
- `revert()`: restore every original and forget the disk again.
- Like `SpatiePermissionsBootstrapper`: no state survives `tenancy()->end()` (Horizon runs many
  tenants in one worker).

## 6. Provisioning and teardown

- `CreateTenantBucket` (after `PrepareTenantStorage`): `assertOwned`, then
  `BucketManager::forDisk($tenant->storage_disk)->create($tenant->storage_bucket)`. Logged like
  every `TenantJob` step. Retry re-runs it safely.
- `DeleteTenantBucket` (after `DeleteTenantStorage`): `assertOwned`, then `->delete(...)`. Runs
  even though the tenant DB (with the `media` rows) is already dropped: it empties the bucket
  itself.
- The `media` table: tenant migration from medialibrary (existing tenants: `tenants:migrate`).

### Local signed downloads
A tenant route `GET /files/local/{path}` named `tenant.storage.local`, middleware `signed` (and the
tenant middleware chain), streams from the tenant's local disk. The bootstrapper registers
`Storage::disk('local')->buildTemporaryUrlsUsing(...)` to sign links to it, so
`$media->getTemporaryUrl()` works on every driver.

## 7. The feature (tenant side)

`App\Models\User implements HasMedia` (`InteractsWithMedia`):
- `avatar`: `singleFile()`, accepts `image/jpeg`, `image/png`, `image/webp`, max 2 MB;
  conversion `thumb` 128×128 (`Fit::Crop`), `nonQueued()`.
- `files`: any type, max 10 MB, many per user.

Pages (auth, tenant domain), linked from the dashboard:
- **Profile → avatar**: upload/replace/remove; shown in the header as `thumb` via a temporary URL.
- **My files**: upload, list (name, size, date), download (temporary URL with
  `ResponseContentDisposition: attachment`), delete. Users see only their own media; a request for
  another user's media id is 404 (policy).

Validation: Form Requests with `file`, `max`, `mimetypes` (avatar). Every upload and delete is
written to the `tenancy` log channel with tenant uuid and media id (no file contents).

## 8. Tests

- **Why not `Storage::fake()` itself:** it swaps a disk for a fresh one rooted in
  `storage/framework/testing/disks/…`, and the bootstrapper would then overwrite that root with the
  tenant's bucket folder (or `fake` would replace the bucket switch, hiding the code under test).
  Running the real `LocalBucketManager` with a test prefix gives the same speed with no MinIO, and
  tests the actual bootstrapper.
- **Default suite** (`FILESYSTEM_DISK=local`, `TENANT_BUCKET_PREFIX=tenanceytest-`): the real
  chain with `LocalBucketManager` under `storage/app/buckets/tenanceytest-*`. Covers bootstrapper
  switch/revert (two tenants, no leakage), provisioning creates the bucket, teardown deletes it,
  guard refusals (wrong name, wrong disk), retry idempotency, avatar + files upload/list/download/
  delete, other-user 404, local signed route (valid, expired, tampered).
- **`#[Group('minio')]`**: `S3BucketManager` against the real MinIO: create/exists/delete
  (including non-empty bucket), a full tenant provision → upload → fetch temporary URL over HTTP
  → teardown. Skipped (`markTestSkipped`) when `MINIO_ENDPOINT` is not reachable.
- `TestCase` cleanup: delete `storage/app/buckets/tenanceytest-*` before and after each test; the
  minio group also deletes `tenanceytest-*` buckets on MinIO.

## 9. Docker (phase 2, design only)

- Service `minio`: `cgr.dev/chainguard/minio@sha256:<digest>`, `minio server /data
  --console-address :9001`, named volume `minio-data`, root user/password in `compose.yaml`.
- Published: API `19010:9000`, dashboard `19011:9001` (Mac's own MinIO keeps 19000/19001).
- `x-laravel-env`: `FILESYSTEM_DISK=minio`, `MINIO_ENDPOINT=http://minio:9000`.
- **Two addresses, and the signature:** a presigned URL's signature covers the host, so a link
  signed for `minio:9000` cannot be rewritten to `localhost:19010` afterwards. Phase 2 adds
  `MINIO_PUBLIC_ENDPOINT` (`http://localhost:19010`): the disk signs temporary URLs with a second
  S3 client built on that endpoint (signing is offline; that client never connects). On Valet
  both addresses are `127.0.0.1:19000`, so phase 1 doesn't need it; phase 2 adds it, with
  `MINIO_ENDPOINT` as the fallback so Valet keeps working unchanged.
- PHP image: add `gd` (with jpeg, png, webp, freetype) and `exif`; nginx
  `client_max_body_size 12m`; `php.ini` `upload_max_filesize 10M`, `post_max_size 12M`.

## 10. Production notes (docs only)
- An IAM key limited to `arn:aws:s3:::<prefix>*` with `s3:CreateBucket`, `s3:DeleteBucket`,
  `s3:ListBucket`, `s3:ListBucketVersions`, `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`,
  `s3:DeleteObjectVersion`.
- `TENANT_BUCKET_PREFIX` must be globally unique; AWS limits buckets per account (default
  10,000).
- New buckets are private by default (Block Public Access); signed URLs need no public access.

## 11. Docs to update
- `docs/STORAGE.md` (new): disks, buckets, the bootstrapper, signed downloads, production.
- `CLAUDE.md`: storage bullets (recorded disk/bucket, guard, never delete buckets by pattern
  outside the test prefix); `README.md`: MinIO on the Mac (launch agent, ports 19000/19001).

## 12. Risks
- **Shared MinIO on the Mac**: other projects may use it. Only exact names via the guard; test
  cleanup only touches `tenanceytest-*`.
- **Flipping `FILESYSTEM_DISK` in dev** leaves existing tenants on their recorded disk (by
  design, D4). Their files are not visible on the new disk.
- **Chainguard `:latest` only** on the free tier: pin by digest, update deliberately.
