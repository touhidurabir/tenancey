# File storage: one setting, one bucket per tenant

How tenant files are stored, on the Mac (local folders or MinIO) and in production (S3). The
design is `docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md`.

## 1. One setting: `FILESYSTEM_DISK`

| Value | Where files go | Reads |
|---|---|---|
| `local` | folders under `storage/app/buckets/` | nothing else |
| `minio` | the Mac's MinIO (S3 protocol, path-style addresses) | `MINIO_ENDPOINT`, `MINIO_KEY`, `MINIO_SECRET`, `MINIO_REGION` |
| `s3` | Amazon S3 | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` (and `AWS_ENDPOINT` for other S3-compatible services) |

Production: set the `AWS_*` keys and `FILESYSTEM_DISK=s3`. No code changes.

The disks are in `config/filesystems.php`. `minio` is the `s3` driver with its own endpoint. Both
`s3` and `minio` throw on failure (`'throw' => true`) instead of returning `false`.

`*_BUCKET` stays empty: each tenant has its own bucket (below), and the central side stores no
files.

## 2. One bucket per tenant

- **Name:** `TENANT_BUCKET_PREFIX` + the tenant's uuid, e.g. `tenancey-01a0fcb8-…`. At most 63
  characters, lowercase letters, digits and hyphens (S3's rules). The prefix must end with `-`;
  `TenantResourceGuard::bucketNameFor()` checks it.
- **On `local`** a bucket is a folder: `storage/app/buckets/{bucket}` (`tenancy.storage.local_root`).
- **Recorded on the tenant row:** `storage_disk` and `storage_bucket`. A tenant keeps the disk it
  was created with, so **changing `FILESYSTEM_DISK` only affects new tenants**. Old ones keep
  working on their recorded disk. Moving a tenant's files between disks is not built.
- **Tenants made before buckets existed:** `php artisan tenants:create-buckets` creates any missing
  bucket for every live tenant. Safe to re-run.

## 3. The pieces

| Piece | Job |
|---|---|
| `App\Tenancy\Storage\BucketManager` | `exists` / `create` / `delete` one bucket, idempotent. `BucketManagers::forDisk()` picks the class by the disk's **driver** (`tenancy.storage.bucket_managers`) |
| `LocalBucketManager` | folders; names are validated, so no `..` can escape the root |
| `S3BucketManager` | real buckets through the disk's S3 client. `delete` empties the bucket first (all versions and delete markers), because S3 refuses to delete a non-empty bucket |
| `App\Jobs\Provisioning\CreateTenantBucket` | provisioning step "Create storage bucket", after "Prepare storage" |
| `App\Jobs\Teardown\DeleteTenantBucket` | teardown step "Delete storage bucket", after "Delete storage". Lists what is really in the bucket, never the `media` rows (that database is already gone) |
| `App\Tenancy\TenantResourceGuard` | both steps (and the command) check the row's `storage_bucket` is EXACTLY `prefix + uuid` and that `storage_disk` is a configured disk |
| `App\Tenancy\TenantBucketBootstrapper` | while tenancy is on, see below |

**`TenantBucketBootstrapper`**, on each tenant switch:
- sets the tenant's disk as `filesystems.default` and `media-library.disk_name`;
- points that disk at the tenant's bucket: `bucket` on S3/MinIO, `root` on local;
- on local, teaches the disk to make signed temporary links (section 4);
- on `revert()`, restores every original value and forgets the cached disk. Horizon runs many
  tenants in one worker, so nothing may leak into the next job.

stancl's `FilesystemTenancyBootstrapper` still suffixes `storage_path()` and the `public` disk.
It no longer touches `local` (removed from `tenancy.filesystem.disks` and `root_override`).

## 4. Files in the app

- **spatie/laravel-medialibrary** on the tenant `User`:
  - `avatar`: one file (JPEG, PNG or WebP), with a 128×128 `thumb` made right away (not queued);
  - `files`: "My files", anything up to 10 MB.
- The `media` table is a **tenant** migration: every tenant database has its own.
- Pages: `/profile` (avatar) and `/files` (My files). Someone else's file answers **404**, not 403.
- **Downloads are 5-minute signed links**:
  - S3/MinIO: the presigned URL straight to the bucket (`…?X-Amz-Signature=…`);
  - local: the tenant route `tenant.storage.local` (`/storage/local/{path}`, `signed:relative`),
    served by `LocalFileController` from the current tenant's bucket.
- The local link is signed **relative** (path and query, not the host) so it works on Valet and
  Docker alike. Moved to another tenant's host it still passes the signature, but that tenant's
  bucket doesn't have the file: 404.
- Laravel's own `local` file route (`serve`) is off: it doesn't initialize tenancy.

## 5. MinIO on the Mac

- Installed with `brew install minio`, run by **our own launch agent**,
  `~/Library/LaunchAgents/local.minio.plist` (label `local.minio`):
  - API (what the app talks to): **http://127.0.0.1:19000**
  - Dashboard: **http://localhost:19001**, `minioadmin` / `minioadmin`
  - Data: `/usr/local/var/minio`; log: `/usr/local/var/log/minio.log`
- **Not `brew services`:** brew starts MinIO on port 9000, which Homebrew PHP-FPM's default pool
  already holds, and gives the dashboard a random port. `brew services list` shows `minio none`
  for our agent; that is expected.

```bash
launchctl print gui/$(id -u)/local.minio | grep -E 'state|pid'            # running?
launchctl kickstart -k gui/$(id -u)/local.minio                          # restart (e.g. after brew upgrade minio)
launchctl bootout gui/$(id -u)/local.minio                               # stop
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/local.minio.plist # start
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:19000/minio/health/live   # 200
```

The Mac's MinIO may be shared with other projects: **never delete buckets by pattern**, except
the tests' `tenanceytest-*`.

**Docker** stays on `local` for now (`FILESYSTEM_DISK: local` in `compose.yaml`): `.env`'s
`127.0.0.1:19000` means the container itself inside Docker. MinIO in Docker is phase 2.

## 6. Tests

- `phpunit.xml` pins `FILESYSTEM_DISK=local` and `TENANT_BUCKET_PREFIX=tenanceytest-`;
  `Tests\TestCase` refuses any other prefix and deletes `storage/app/buckets/tenanceytest-*`
  before and after each test.
- Tests marked `#[Group('minio')]` (`S3BucketManagerTest`, `MinioEndToEndTest`) use the real MinIO
  from `.env`, delete only `tenanceytest-*` buckets, and are **skipped** when MinIO isn't
  reachable. Run them alone with `php artisan test --group=minio`.

## 7. Production (S3)

- An IAM key limited to `arn:aws:s3:::<prefix>*` (and `arn:aws:s3:::<prefix>*/*`) with
  `s3:CreateBucket`, `s3:DeleteBucket`, `s3:ListBucket`, `s3:ListBucketVersions`, `s3:GetObject`,
  `s3:PutObject`, `s3:DeleteObject`, `s3:DeleteObjectVersion`.
- **`TENANT_BUCKET_PREFIX` must be globally unique**: bucket names are shared by every AWS
  account. A taken name stops provisioning with "belongs to another account".
- AWS limits buckets per account (10,000 by default).
- New buckets are private (Block Public Access). That is fine: signed links need no public access.
