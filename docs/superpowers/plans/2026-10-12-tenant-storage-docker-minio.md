# Tenant Storage on Docker MinIO (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run tenant file storage on a MinIO container in the Docker setup, one small learning part at a time, while Valet keeps using the Mac's MinIO unchanged.

**Architecture:** A `minio` service (Chainguard image pinned by digest, named volume, ports 19010/19011) joins `compose.yaml`. PHP containers talk to it as `http://minio:9000`. The browser can't reach that name, and a presigned URL's signature covers the host, so links are signed for `http://localhost:19010` by a second S3 client built from `MINIO_PUBLIC_ENDPOINT` (signing is offline; that client never connects). Unset, it falls back to normal signing, so Valet is untouched. The PHP image gains `gd` and `exif` for medialibrary.

**Tech Stack:** Docker Desktop / Compose (Intel Mac), `cgr.dev/chainguard/minio` (MinIO server, distroless), Laravel 13, PHP 8.4, aws/aws-sdk-php (via league/flysystem-aws-s3-v3), spatie/laravel-medialibrary 11.

**Spec:** `docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md` (§9 is this phase). Phase 1's plan: `docs/superpowers/plans/2026-10-11-tenant-storage-buckets-valet.md`.

## Global Constraints

- **Never commit or push.** Every task ends with a hand-off: Claude lists what's ready and prints the `git add` / `git commit` commands. Claude runs read-only git only.
- **The developer runs every `docker` / `docker compose` command by hand.** Claude writes files, explains every line and flag, and prints the commands with the expected output. Claude may run read-only checks to verify its own claims (e.g. `docker buildx imagetools inspect`, `docker compose config`), never the part's commands.
- One part at a time: the next part starts only after the developer reports the part's check passed.
- Ports: Docker MinIO API **19010**, dashboard **19011**. The Mac's MinIO keeps **19000/19001** and is never touched (it is shared with other projects). Already taken: 80, 3306, 3307, 6379, 8000, 8025, 8080, 8081, 8082, 19000, 19001.
- Docker URLs: `http://tenancey.localhost:8000`, tenants `http://{subdomain}.tenancey.localhost:8000`.
- MinIO image: `cgr.dev/chainguard/minio@sha256:dc455cdd4db6e390b1b26514f15ff37681a777bf8dab41aac445a0259f751e56` (`:latest` on 2026-10-12; verified with `docker buildx imagetools inspect`). Entrypoint `/usr/bin/minio`, user `65532`, no shell.
- Docker MinIO root user/password: `minioadmin` / `minioadmin` (local dev only, written in `compose.yaml`).
- **Never run `php artisan config:cache`, `route:cache` or `optimize`** in either setup (`bootstrap/cache` is shared).
- Never drop databases, users or buckets by pattern, except the tests' `tenanceytest-*`. Never name the private reference projects in repo files.
- Two notes tracks per part:
  - `docs/DOCKER.md` (committed): a short "File storage (MinIO)" reference section, grown per part.
  - `docs/docker-learning/minio-N-*.md` (git-excluded via `.git/info/exclude`): the full teaching text, verbatim as delivered in chat. Add a row to `docs/docker-learning/README.md`, and new commands/options to its tables. `minio-0-mac-fix.md` already exists.

## Spec refinements (decided while planning)

Task 1 records these in spec §9.
1. **Upload limits are already enough.** `docker/php/php.ini` has `upload_max_filesize`/`post_max_size = 20M` and nginx `client_max_body_size 20M`, above the app's 10 MB. No change.
2. **No healthcheck on `minio`.** The image is distroless (no shell, no `curl`, no `mc`), so there's nothing to run inside it. Nothing needs MinIO at boot: S3 calls happen in requests and jobs. PHP services wait for `service_started` only. Health is checked from the Mac: `curl http://localhost:19010/minio/health/live`.
3. **Laravel's `temporary_url` disk option is not used.** It replaces the host *after* signing, which breaks the V4 signature. A second client signs for the public endpoint instead (`App\Tenancy\Storage\S3PublicUrlSigner`, installed by `TenantBucketBootstrapper`).
4. **`MinioEndToEndTest` signs for the internal endpoint** (it clears `public_endpoint`), because inside a container `localhost:19010` is the container itself. Signing for the public endpoint is pinned by its own tests.
5. **The deferred phase-1 minor "upgrade steps missing from the docs"** is done in Task 4, where Docker's database needs exactly `migrate`, `tenants:migrate`, `tenants:create-buckets`.

## Review Focus

1. **`MINIO_PUBLIC_ENDPOINT` unset or empty** (Valet): links must be signed exactly as in phase 1, for `MINIO_ENDPOINT`. Pinned in Task 1.
2. **Download options survive the second client:** `ResponseContentDisposition` must reach the signed URL, or "My files" downloads open in the browser instead of saving. Pinned in Task 1.
3. **A tenant switch in a long-lived worker:** after a MinIO tenant with a public endpoint, the next tenant (or central) must not keep its signing callback. Pinned in Task 1 (switch, then sign again with the endpoint cleared).
4. **Existing Docker tenants after the migration** are recorded on `local` and must keep working, while new ones go to MinIO. Checked by hand in Task 4.
5. **MinIO data survives `docker compose down`** and is erased only by `down -v`. Checked by hand in Task 3.

---

### Task 1: Sign links for the browser's address (MinIO 1, on Valet)

**Files:**
- Create: `app/Tenancy/Storage/S3PublicUrlSigner.php`, `tests/Feature/Storage/PublicEndpointSigningTest.php`
- Modify: `config/filesystems.php` (`minio.public_endpoint`), `app/Tenancy/TenantBucketBootstrapper.php`, `tests/Feature/Storage/MinioEndToEndTest.php` (setUp), `.env.example`, `docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md` (§9 refinements)

**Interfaces:**
- Consumes: `TenantBucketBootstrapper::bootstrap()` (phase 1), `Tests\Concerns\UsesMinio`, `BucketManagers::forDisk()`.
- Produces: `S3PublicUrlSigner::temporaryUrl(array $disk, string $path, DateTimeInterface $expiration, array $options = []): string`; disk key `filesystems.disks.minio.public_endpoint` (env `MINIO_PUBLIC_ENDPOINT`).

- [ ] **Step 1: Teach the idea (no code yet)**

Explain, with a diagram in chat: one MinIO, two addresses. PHP inside Docker uses `minio:9000` (the service name on the Compose network). The browser on the Mac uses `localhost:19010` (the published port). A presigned URL is a promise signed with the secret key over method, path, query **and host**. Rewriting the host afterwards breaks the promise (`SignatureDoesNotMatch`). So we sign for the address the browser will use. Let the developer see it on Valet with the Mac's MinIO:
```bash
php artisan tinker --execute 'tenancy()->initialize(App\Models\Tenant::where("subdomain","gemma")->firstOrFail()); Storage::put("probe.txt","hi"); echo Storage::temporaryUrl("probe.txt", now()->addMinutes(5));'
```
Then `curl -s "<url>"` → `hi`; `curl -s "<url with 127.0.0.1 replaced by localhost>"` → `SignatureDoesNotMatch` XML. (The developer runs both; `gemma` is their MinIO tenant.) Clean up: the same tinker line with `Storage::delete("probe.txt");`.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Storage/PublicEndpointSigningTest.php`:
```php
<?php

namespace Tests\Feature\Storage;

use App\Models\Tenant;
use App\Tenancy\Storage\BucketManagers;
use App\Tenancy\Storage\S3PublicUrlSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesMinio;
use Tests\TestCase;

class PublicEndpointSigningTest extends TestCase
{
    use UsesMinio;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * A tenant recorded on `minio`, without creating a bucket: signing never connects.
     */
    protected function minioTenant(string $subdomain): Tenant
    {
        $tenant = $this->createTenant($subdomain);
        DB::table('tenants')->where('id', $tenant->id)->update(['storage_disk' => 'minio']);

        return $tenant->fresh();
    }

    public function test_links_are_signed_for_the_public_endpoint_with_their_download_options(): void
    {
        config()->set('filesystems.disks.minio.endpoint', 'http://minio:9000');
        config()->set('filesystems.disks.minio.public_endpoint', 'http://localhost:19010');
        $tenant = $this->minioTenant('acme');

        tenancy()->initialize($tenant);
        $url = Storage::temporaryUrl('1/report.txt', now()->addMinutes(5), ['ResponseContentDisposition' => 'attachment; filename="report.txt"']);
        tenancy()->end();

        $this->assertStringStartsWith("http://localhost:19010/{$tenant->storage_bucket}/1/report.txt?", $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringContainsString('response-content-disposition=attachment', $url);
    }

    public function test_without_a_public_endpoint_links_are_signed_for_the_endpoint_and_nothing_leaks_across_tenants(): void
    {
        config()->set('filesystems.disks.minio.endpoint', 'http://minio:9000');
        config()->set('filesystems.disks.minio.public_endpoint', 'http://localhost:19010');
        $acme = $this->minioTenant('acme');
        $beta = $this->minioTenant('beta');

        tenancy()->initialize($acme);
        Storage::temporaryUrl('a.txt', now()->addMinutes(5));
        tenancy()->end();

        config()->set('filesystems.disks.minio.public_endpoint', null); // as on Valet
        tenancy()->initialize($beta);
        $url = Storage::temporaryUrl('a.txt', now()->addMinutes(5));
        tenancy()->end();

        $this->assertStringStartsWith("http://minio:9000/{$beta->storage_bucket}/a.txt?", $url);
    }

    #[Group('minio')]
    public function test_a_link_works_only_on_the_host_it_was_signed_for(): void
    {
        $this->skipUnlessMinioIsReachable();
        $endpoint = (string) config('filesystems.disks.minio.endpoint');
        if (parse_url($endpoint, PHP_URL_HOST) !== '127.0.0.1') {
            $this->markTestSkipped('Needs the Mac\'s MinIO, reachable as both 127.0.0.1 and localhost.');
        }
        $this->deleteTestBucketsOnMinio();

        try {
            BucketManagers::forDisk('minio')->create('tenanceytest-signing');
            $disk = [...config('filesystems.disks.minio'), 'bucket' => 'tenanceytest-signing'];
            Storage::build($disk)->put('probe.txt', 'signed for localhost');

            $public = str_replace('127.0.0.1', 'localhost', $endpoint);
            $url = S3PublicUrlSigner::temporaryUrl([...$disk, 'public_endpoint' => $public], 'probe.txt', now()->addMinutes(5));

            $this->assertSame('signed for localhost', Http::get($url)->body());
            $this->assertSame(403, Http::get(str_replace('localhost', '127.0.0.1', $url))->status()); // same server, other host: refused
        } finally {
            $this->deleteTestBucketsOnMinio();
        }
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Storage/PublicEndpointSigningTest.php`
Expected: FAIL. The first test's URL starts with `http://minio:9000/` (no public signing yet); the third errors with class `S3PublicUrlSigner` not found. The second passes already (it pins today's behavior); note that in the ledger.

- [ ] **Step 4: Implement**

`config/filesystems.php`, in the `minio` disk after `endpoint`:
```php
            'public_endpoint' => env('MINIO_PUBLIC_ENDPOINT'), // the browser's address for signed links; unset = endpoint (Valet)
```

`app/Tenancy/Storage/S3PublicUrlSigner.php`:
```php
<?php

namespace App\Tenancy\Storage;

use Aws\S3\S3Client;
use DateTimeInterface;

/**
 * Signs temporary links for the address the BROWSER uses, when it differs from the one PHP uses
 * (Docker: PHP reaches MinIO as minio:9000, the browser as localhost:19010). A presigned URL's
 * signature covers the host, so the host can't be swapped after signing (Laravel's
 * `temporary_url` option does that, and breaks it). Signing is offline: this client never connects.
 */
class S3PublicUrlSigner
{
    /**
     * @param  array<string, mixed>  $disk  the disk's config, with `public_endpoint` and the tenant's `bucket`
     * @param  array<string, mixed>  $options  GetObject parameters, e.g. ResponseContentDisposition
     */
    public static function temporaryUrl(array $disk, string $path, DateTimeInterface $expiration, array $options = []): string
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $disk['region'],
            'endpoint' => $disk['public_endpoint'],
            'use_path_style_endpoint' => $disk['use_path_style_endpoint'] ?? false,
            'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        ]);

        $command = $client->getCommand('GetObject', [
            'Bucket' => $disk['bucket'],
            'Key' => ltrim($path, '/'),
            ...$options,
        ]);

        return (string) $client->createPresignedRequest($command, $expiration)->getUri();
    }
}
```

`app/Tenancy/TenantBucketBootstrapper.php`: turn the local-only signing block into `if … elseif`:
```php
        if ($manager instanceof LocalBucketManager) {
            // S3/MinIO sign their own links. Local ones go through a tenant route, signed relative
            // (path + query) and made absolute with the tenant's own host (Tenant::url()). The
            // host isn't signed, so the tenant key is: paths ({media id}/{name}) repeat across
            // tenants, and a link replayed on another tenant's host must not serve its file.
            Storage::disk($disk)->buildTemporaryUrlsUsing(
                fn (string $path, DateTimeInterface $expiration) => $tenant->url(
                    URL::temporarySignedRoute('tenant.storage.local', $expiration, ['path' => $path, 'tenant' => $tenant->getTenantKey()], absolute: false),
                ),
            );
        } elseif (filled(config("filesystems.disks.{$disk}.public_endpoint"))) {
            // S3/MinIO reached under another name by the browser (Docker): sign for that name.
            Storage::disk($disk)->buildTemporaryUrlsUsing(
                fn (string $path, DateTimeInterface $expiration, array $options = []) => S3PublicUrlSigner::temporaryUrl(
                    config("filesystems.disks.{$disk}"), $path, $expiration, $options,
                ),
            );
        }
```
(import `App\Tenancy\Storage\S3PublicUrlSigner`; revert() already forgets the disk, which drops the callback.)

`tests/Feature/Storage/MinioEndToEndTest.php` `setUp()`, after the `filesystems.default` line:
```php
        // Sign for the endpoint PHP itself uses: in Docker the public one (localhost:19010) is the
        // container itself. PublicEndpointSigningTest covers public signing.
        config()->set('filesystems.disks.minio.public_endpoint', null);
```

`.env.example`, in the MinIO block: `MINIO_PUBLIC_ENDPOINT=   # only when the browser reaches MinIO by another address (Docker sets it)`.

Spec §9: append "Refinements (2026-10-12, phase 2 plan)" with the five refinements above.

- [ ] **Step 5: Run to verify they pass; suite; Pint**

Run: `php artisan test --compact tests/Feature/Storage/PublicEndpointSigningTest.php` → 3 passed (the third runs, MinIO up).
Run: `php artisan test --compact` → all pass. `vendor/bin/pint --dirty --format agent`.

- [ ] **Step 6: Notes, hand-off**

Write `docs/docker-learning/minio-1-two-addresses.md` (verbatim teaching: the two addresses, what a signature covers, the curl demo, the code) and a README row. Start the "File storage (MinIO)" section of `docs/DOCKER.md` (the two addresses, `MINIO_PUBLIC_ENDPOINT`).
```bash
git add app/Tenancy/Storage/S3PublicUrlSigner.php app/Tenancy/TenantBucketBootstrapper.php config/filesystems.php .env.example tests/Feature/Storage/PublicEndpointSigningTest.php tests/Feature/Storage/MinioEndToEndTest.php docs/superpowers/specs/2026-10-11-tenant-storage-buckets-design.md docs/DOCKER.md
git commit -m "Sign MinIO links for the browser's address"
php artisan horizon:terminate
```

---

### Task 2: `gd` and `exif` in the PHP image (MinIO 2)

**Files:**
- Modify: `docker/php/Dockerfile`

**Interfaces:**
- Produces: image `tenancey-php:dev` with `gd` (JPEG, PNG, WebP, FreeType) and `exif`, used by `app`, `horizon`, `reverb`, `scheduler`.

- [ ] **Step 1: Add one layer after the phpredis layer**

```dockerfile
# gd (thumbnails: JPEG, PNG, WebP, FreeType) and exif (photo orientation) for medialibrary.
# The -dev packages bring the libraries gd is compiled against (and needs at runtime).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install gd exif \
    && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 2: Teach and hand the commands over**

Explain: why `configure` before `install` (gd's optional formats are compile-time switches), why one `RUN` (one layer, apt lists removed in the same layer), and the layer cache (layers above are `CACHED`, this one and everything below rebuild). One image, four services: `docker compose build` rebuilds it once.

Developer runs:
```bash
docker compose build app
docker compose up -d                       # recreates the four PHP services on the new image
docker compose exec app php -m | grep -E '^(gd|exif)$'
docker compose exec app php -r 'foreach (gd_info() as $k => $v) if (is_bool($v)) echo $k, ": ", $v ? "yes" : "no", PHP_EOL;'
docker compose exec app composer check-platform-reqs
docker compose exec app php artisan test --compact tests/Feature/Tenant/AvatarTest.php
```
Expected: `exif` and `gd` listed; JPEG/PNG/WebP Support `yes`; every platform line `success` (medialibrary's `ext-exif` included); AvatarTest 3 passed (the thumbnail needs gd).

- [ ] **Step 3: Notes, hand-off**

`docs/docker-learning/minio-2-php-image.md`, README row and new commands; `docs/DOCKER.md` (image now has gd/exif).
```bash
git add docker/php/Dockerfile docs/DOCKER.md
git commit -m "Add gd and exif to the PHP image"
```

---

### Task 3: The `minio` service (MinIO 3)

**Files:**
- Modify: `compose.yaml` (service `minio`, volume `minio-data`)

**Interfaces:**
- Produces: service `minio` reachable as `http://minio:9000` on the Compose network; Mac ports `19010` (API) and `19011` (dashboard); named volume `minio-data`.

- [ ] **Step 1: Add the service (after `mailpit`) and the volume**

```yaml
  minio:
    # Chainguard's free tier only publishes :latest, so pin the digest (:latest on 2026-10-12).
    image: cgr.dev/chainguard/minio@sha256:dc455cdd4db6e390b1b26514f15ff37681a777bf8dab41aac445a0259f751e56
    command: server /data --console-address :9001 # the image's entrypoint is `minio`
    environment:
      MINIO_ROOT_USER: minioadmin # local dev only, like MySQL's password
      MINIO_ROOT_PASSWORD: minioadmin
    ports:
      - "19010:9000" # S3 API: signed links in the browser go to localhost:19010
      - "19011:9001" # dashboard: http://localhost:19011 (the Mac's MinIO keeps 19000/19001)
    volumes:
      - minio-data:/data
    # No healthcheck: the image has no shell or client to run one (check from the Mac with curl).
    restart: unless-stopped
```
and under `volumes:` add `minio-data:`.

- [ ] **Step 2: Teach and hand the commands over**

Explain: pinning by digest vs a tag (`docker buildx imagetools inspect` shows the index digest and per-platform manifests), entrypoint + `command` (Compose's `command` is the arguments after `minio`), distroless and the non-root user 65532, why no healthcheck, the two ports, the named volume.

Developer runs:
```bash
docker compose config minio                       # the service as Compose reads it
docker compose up -d minio
docker compose ps minio
docker compose logs minio                         # API and WebUI addresses, no errors
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:19010/minio/health/live   # 200
docker compose exec minio sh                      # fails: no shell in a distroless image
```
Then http://localhost:19011, `minioadmin` / `minioadmin`, create a bucket `scratch` by hand; `docker compose down`, `docker compose up -d`, the bucket is still there (named volume); delete `scratch`.

Expected: `200`; the dashboard opens. **If the log says the drive is not writable** (`/data` created root-owned for user 65532): rule on the smallest fix and ledger it (first choice: `user: "0"` on this service only, local dev, with a comment; teach why).

- [ ] **Step 3: Notes, hand-off**

`docs/docker-learning/minio-3-service.md`, README rows (`docker buildx imagetools inspect`, distroless); `docs/DOCKER.md` (service, ports, dashboard, volume, no healthcheck).
```bash
git add compose.yaml docs/DOCKER.md
git commit -m "Add a MinIO service to Docker"
```

---

### Task 4: Switch Docker to MinIO (MinIO 4)

**Files:**
- Modify: `compose.yaml` (`x-laravel-env`, `x-php.depends_on`), `docs/DOCKER.md`, `docs/STORAGE.md` (§2 upgrade steps, §5 Docker), `README.md` (§7 upgrade steps; Docker ports), `CLAUDE.md` (Docker line: MinIO ports)

**Interfaces:**
- Consumes: `MINIO_PUBLIC_ENDPOINT` (Task 1), image with gd (Task 2), service `minio` (Task 3).

- [ ] **Step 1: Replace the phase-1 pin in `x-laravel-env`**

Replace `FILESYSTEM_DISK: local # MinIO in Docker comes in phase 2…` with:
```yaml
  FILESYSTEM_DISK: minio
  MINIO_ENDPOINT: http://minio:9000 # PHP containers reach MinIO by its service name
  MINIO_PUBLIC_ENDPOINT: http://localhost:19010 # signed links are opened by the browser on the Mac
  MINIO_KEY: minioadmin
  MINIO_SECRET: minioadmin
```
and in `x-php.depends_on` add:
```yaml
    minio:
      condition: service_started # no healthcheck to wait for (see the service)
```

- [ ] **Step 2: Teach and hand the commands over**

Explain: Compose env beats `.env` (stage 4), so Valet's `.env` stays on the Mac's MinIO; `up -d` recreates only the containers whose settings changed (stage 7); new tenants get `minio`, old Docker tenants keep `local` (the recorded disk).

Developer runs:
```bash
docker compose up -d
docker compose exec app php artisan config:show filesystems.default         # minio
docker compose exec app php artisan migrate                                 # storage_disk/storage_bucket (old tenants backfilled as local)
docker compose exec app php artisan tenants:migrate                         # the media table in every tenant
docker compose exec app php artisan tenants:create-buckets                  # local folders for old tenants
docker compose restart horizon                                              # job code changed since it started
```
Browser:
1. http://tenancey.localhost:8000 → create tenant `docker1`; the progress page shows "Create storage bucket".
2. http://localhost:19011: bucket `tenancey-<uuid>` exists.
3. Sign in to `docker1`, Profile → avatar: the thumbnail shows; DevTools → Network: it comes from `localhost:19010/tenancey-…?X-Amz-Signature=…`.
4. My files: upload, download (saves), delete.
5. An old Docker tenant: avatar upload works (local).
6. Delete `docker1`: the bucket disappears from the 19011 dashboard.
7. Valet still works on the Mac's MinIO (http://localhost:19001 shows none of Docker's buckets).

- [ ] **Step 3: Docs, notes, hand-off**

- `docs/STORAGE.md` §2 and `README.md` §7: an "Upgrading an existing install" list: `php artisan migrate`, `php artisan tenants:migrate`, `php artisan tenants:create-buckets` (and the Docker forms). §5: Docker's MinIO (19010/19011, `MINIO_PUBLIC_ENDPOINT`), replacing "Docker stays on local".
- `CLAUDE.md` Docker bullet: add MinIO `localhost:19010` (dashboard `19011`).
- `docs/DOCKER.md`: the switch, the upgrade commands, the browser check.
- `docs/docker-learning/minio-4-switch.md` and README row.
```bash
git add compose.yaml docs/DOCKER.md docs/STORAGE.md README.md CLAUDE.md
git commit -m "Run Docker's tenant storage on MinIO"
```

---

### Task 5: Tests in Docker, and wrap-up (MinIO 5)

**Files:**
- Modify: `docs/DOCKER.md` (tests, wrap-up), `docs/docker-learning/README.md`

- [ ] **Step 1: The suite in Docker, the minio group included**

Developer runs:
```bash
docker compose exec app php artisan test --compact
docker compose exec app php artisan test --compact --group=minio
```
Expected: all pass. In the container the `minio` group talks to `minio:9000` (the container's MinIO, `tenanceytest-*` buckets only); `PublicEndpointSigningTest`'s host test is skipped (it needs the Mac's `127.0.0.1`/`localhost` pair). If anything fails, systematic-debugging, then a ledgered ruling.

- [ ] **Step 2: Wrap-up teaching**

One page: the whole storage picture in both setups (a table: Valet vs Docker, endpoint, public endpoint, ports, dashboard, where buckets live), the ideas learned in MinIO 0–5, and a cold-start check (`docker compose down`, `up -d`, avatar still shows).

- [ ] **Step 3: Notes, hand-off**

`docs/docker-learning/minio-5-wrap-up.md`, README rows; `docs/DOCKER.md` final touches.
```bash
git add docs/DOCKER.md
git commit -m "Document tests and the storage picture in Docker"
```

---

## Self-review notes

- Spec §9 coverage: `minio` service with digest, command, volume, root user (Task 3); ports 19010/19011 (Task 3); `x-laravel-env` FILESYSTEM_DISK and MINIO_ENDPOINT (Task 4); `MINIO_PUBLIC_ENDPOINT` second signing client with endpoint fallback (Task 1); gd/exif (Task 2); upload limits (refinement 1: already 20M).
- Review Focus tests: (1) and (3) `test_without_a_public_endpoint_links_are_signed_for_the_endpoint_and_nothing_leaks_across_tenants`; (2) `test_links_are_signed_for_the_public_endpoint_with_their_download_options`; (4) and (5) by hand in Tasks 4 and 3.
- Types: `S3PublicUrlSigner::temporaryUrl(array, string, DateTimeInterface, array): string` is used identically in the bootstrapper and the test; `public_endpoint` is the only new config key.
