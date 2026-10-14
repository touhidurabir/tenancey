# File storage: one setting, one bucket per tenant

How tenant files are stored in development (local folders or the MinIO container) and in
production (S3). The design is `docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md`.

## 1. One setting: `FILESYSTEM_DISK`

| Value | Where files go | Reads |
|---|---|---|
| `local` | folders under `storage/app/buckets/` | nothing else |
| `minio` | the MinIO container (S3 protocol, path-style addresses) | `MINIO_ENDPOINT`, `MINIO_KEY`, `MINIO_SECRET`, `MINIO_REGION` |
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
- **Provisioning refuses** a `FILESYSTEM_DISK` it can't make buckets on (a typo, or a disk with no
  bucket manager) before saving the tenant, so no tenant is recorded on a disk it can't tear down.
- **Keep `TENANT_BUCKET_PREFIX` once tenants exist.** Buckets are checked against
  `prefix + uuid`, so a new prefix makes the bucket steps of existing tenants refuse to run.
- **Recorded on the tenant row:** `storage_disk` and `storage_bucket`. A tenant keeps the disk it
  was created with, so **changing `FILESYSTEM_DISK` only affects new tenants**. Old ones keep
  working on their recorded disk. Moving a tenant's files between disks is not built.
- **Upgrading an install made before buckets existed**, in this order (inside the `app` container:
  `docker compose exec app php artisan …`):
  1. `php artisan migrate`: adds `storage_disk`/`storage_bucket`; existing tenants are recorded
     on `local`.
  2. `php artisan tenants:migrate`: the `media` table in every tenant database.
  3. `php artisan tenants:create-buckets`: creates any missing bucket for every live tenant. Safe
     to re-run.
  4. Restart Horizon (`docker compose restart horizon`).

  In Docker, prefix each with `docker compose exec app` and restart with
  `docker compose restart horizon` (`docs/DOCKER.md`).

## 3. The pieces

| Piece | Job |
|---|---|
| `App\Tenancy\Storage\BucketManager` | `exists` / `create` / `delete` one bucket, idempotent. `BucketManagers::forDisk()` picks the class by the disk's **driver** (`tenancy.storage.bucket_managers`) |
| `LocalBucketManager` | folders; names are validated, so no `..` can escape the root |
| `S3BucketManager` | real buckets through the disk's S3 client. `delete` empties the bucket first (all versions and delete markers), because S3 refuses to delete a non-empty bucket |
| `App\Jobs\Provisioning\CreateTenantBucket` | provisioning step "Create storage bucket", after "Prepare storage" |
| `App\Jobs\Teardown\DeleteTenantBucket` | teardown step "Delete storage bucket", after "Delete storage". Lists what is really in the bucket, never the `media` rows (that database is already gone) |
| `App\Tenancy\TenantResourceGuard` | `assertBucketOwned()`, used by both steps and the command, checks the row's `storage_bucket` is EXACTLY `prefix + uuid` and that `storage_disk` is a configured disk. The database and folder steps use `assertOwned()`, which ignores bucket settings, so a bucket setting gone wrong never blocks them |
| `App\Tenancy\TenantBucketBootstrapper` | while tenancy is on, see below |

**`TenantBucketBootstrapper`**, on each tenant switch:
- sets the tenant's disk as `filesystems.default` and `media-library.disk_name`;
- points that disk at the tenant's bucket: `bucket` on S3/MinIO, `root` on local;
- on local, teaches the disk to make signed temporary links (section 4);
- on `revert()`, restores every original value and forgets the cached disk. Horizon runs many
  tenants in one worker, so nothing may leak into the next job.

stancl's `FilesystemTenancyBootstrapper` still suffixes `storage_path()` and the `public` disk.
It no longer touches `local` (removed from `tenancy.filesystem.disks` and `root_override`).
For a tenant whose disk is NOT `local` (MinIO, S3), `TenantBucketBootstrapper` still points
`local` at the tenant's own folder (`storage/tenant_{uuid}/app`), so code that names `local`
never writes into the shared central folder.

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
- The local link is signed **relative** (path and query, not the host), so it works whatever host
  and scheme the browser used. The host isn't signed, so the **tenant key is**: the link carries `tenant=<uuid>`,
  and `LocalFileController` answers 403 on any other tenant. (Paths like `1/report.pdf` repeat in
  every tenant's bucket, so "the file isn't there" would not be enough.)
- Laravel's own `local` file route (`serve`) is off: it doesn't initialize tenancy.

## 5. MinIO in Docker

Development uses the `minio` container (`docs/DOCKER.md` has the service details):
- PHP talks to it as **`http://minio:9000`** (`MINIO_ENDPOINT`): bucket creation, uploads, deletes.
- The browser reaches it at `MINIO_PUBLIC_ENDPOINT`: **`http://localhost:9000`** in port mode (the
  published port), **`https://s3.tenancey.test`** in docker-edge mode (through Traefik). Links are
  signed for that address: a signature covers the host, and the browser can't reach `minio`. The
  `Host` header reaches MinIO unchanged, so it checks the signature against the host it was made for.
- Dashboard: **http://localhost:9001** (port mode) or **https://minio.tenancey.test** (docker-edge
  mode), `minioadmin` / `minioadmin`. Data: the `minio-data`
  volume.

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:9000/minio/health/live      # 200 (port mode)
curl -s -o /dev/null -w '%{http_code}\n' https://s3.tenancey.test/minio/health/live   # 200 (docker-edge mode)
```

**The Mac's own MinIO** (`127.0.0.1:19000`, launch agent `local.minio`) is what the old Valet setup
used. It still holds those buckets, unused, and **it may be shared with other projects: never
delete buckets there by pattern.**

## 6. Tests

- `phpunit.xml` pins `FILESYSTEM_DISK=local` (a `<server>` entry, so it beats `.env`'s
  `FILESYSTEM_DISK=minio`) and `TENANT_BUCKET_PREFIX=tenanceytest-`;
  `Tests\TestCase` refuses any other prefix and deletes `storage/app/buckets/tenanceytest-*`
  before and after each test.
- Tests marked `#[Group('minio')]` (`S3BucketManagerTest`, `MinioEndToEndTest`, one test in
  `PublicEndpointSigningTest`) use the `minio` container, delete only `tenanceytest-*` buckets, and are **skipped** when MinIO
  isn't reachable. Run them alone with `docker compose exec app php artisan test --group=minio`.

## 7. Production (S3)

- An IAM key limited to `arn:aws:s3:::<prefix>*` (and `arn:aws:s3:::<prefix>*/*`) with
  `s3:CreateBucket`, `s3:DeleteBucket`, `s3:ListBucket`, `s3:ListBucketVersions`, `s3:GetObject`,
  `s3:PutObject`, `s3:DeleteObject`, `s3:DeleteObjectVersion`.
- **`TENANT_BUCKET_PREFIX` must be globally unique**: bucket names are shared by every AWS
  account. A taken name stops provisioning with "belongs to another account".
- AWS limits buckets per account (10,000 by default).
- New buckets are private (Block Public Access). That is fine: signed links need no public access.
