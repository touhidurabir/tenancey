# Docker for Local Development Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the whole app (web, queues, WebSockets, scheduler, MySQL, Redis, mail, asset build) in Docker for local development, side by side with Valet, built one small stage at a time so the developer learns Docker.

**Architecture:** One custom PHP image (`php:8.4-fpm` + `pdo_mysql`, `pcntl`, `redis`, Composer) runs four services: `app` (PHP-FPM), `horizon`, `reverb`, `scheduler`. Official images run `nginx`, `mysql`, `redis`, `mailpit` and a one-off `node` build. The project folder is bind-mounted, and Docker-only settings are Compose `environment:` overrides that win over `.env` (Laravel's dotenv is immutable). Two small code changes let Valet and Docker share the folder: `Tenant::url()` keeps the port, and Docker builds assets into `public/build-docker`.

**Tech Stack:** Docker Desktop 29.8 / Compose v5.5 (Intel Mac), Laravel 13, PHP 8.4, MySQL 8.4, Redis 7.4, nginx 1.30, Node 22.12, Mailpit v1.29, laravel-vite-plugin 3.2.

**Spec:** `docs/superpowers/specs/2026-10-07-docker-local-dev-design.md`

## Global Constraints

- **Never commit or push.** Every task ends with a hand-off: Claude lists what's ready and prints the `git add` / `git commit` commands for the developer to run. Claude runs read-only git only.
- **The developer runs every `docker` / `docker compose` command by hand.** Claude writes files, explains every line, and prints the commands with the expected output. Claude may run read-only checks to verify its own claims (e.g. `docker buildx imagetools inspect`), never the stage's commands.
- One stage at a time: the next stage starts only after the developer reports the stage's check passed.
- Valet (`tenancey.test`), the supervisor programs (`tenancey-horizon`, `tenancey-reverb`), the Mac's MySQL/Redis, and other projects (Reverb on 8080) are never touched.
- Host ports: `8000` (web), `8082` (Reverb), `3307` (MySQL), `8025` (Mailpit inbox). Already taken on the Mac: 80, 3306, 6379, 8080, 8081, 2525.
- Docker URLs: `http://tenancey.localhost:8000` and `http://{subdomain}.tenancey.localhost:8000`.
- Image tags (verified to exist on 2026-10-08): `php:8.4-fpm`, `composer:2`, `nginx:1.30-alpine`, `mysql:8.4`, `redis:7.4-alpine`, `axllent/mailpit:v1.29`, `node:22.12-bookworm-slim`.
- Docker MySQL root password: `docker` (local dev only, written in `compose.yaml`).
- **Never run `php artisan config:cache` or `optimize`** in either setup: `bootstrap/cache/config.php` is shared.
- `APP_ENV` stays `local` in Docker (Horizon's dashboard auth relies on it).
- Never drop databases or users by pattern. Never name the private reference projects in repo files.
- Two notes tracks per stage:
  - `docs/DOCKER.md` (committed): short reference section per stage.
  - `docs/docker-learning/stage-N-*.md` (git-excluded via `.git/info/exclude`): the full step-by-step teaching text, verbatim as delivered in chat. Add a row to `docs/docker-learning/README.md`, and new commands/options to its tables.

## Spec refinements (decided while planning)

These differ slightly from the spec; Task 1 records them in the spec file.
1. **No `.dockerignore`.** The image build context is `docker/php/` (it only needs `php.ini`), so the project folder is never sent to the builder. A `.dockerignore` returns in the production phase, when the image will copy the code.
2. **nginx mounts only `./public`, read-only.** It serves static files and passes PHP to `app`; it needs nothing else.
3. **`node` runs on demand** (`docker compose run --rm node`) under a Compose `profiles: [tools]`, so `docker compose up` doesn't re-run `npm ci` every time.
4. **Stage 4's check** is `/up` returning 200 plus a "Vite manifest not found at …/public/build-docker/manifest.json" error page. That error proves Laravel runs in Docker and reads `ASSET_BUILD_DIRECTORY`. Logging in moves to Stage 5's check, once assets are built.
5. **Tests in Docker** (Stage 9) pass `-e APP_URL=http://tenancey.test -e CENTRAL_DOMAIN=tenancey.test` to `docker compose exec`. Tests assert `tenancey.test` hosts, and phpunit.xml's `force` can't override them: it doesn't touch `$_SERVER`, which Laravel reads first.
6. `horizon` gets `stop_grace_period: 660s` and `reverb` gets `30s`, the Compose counterparts of supervisor's `stopwaitsecs`.

## Review Focus

1. **`APP_URL` with a port, a trailing slash, or https.** `Tenant::url()` must keep `:8000`, drop nothing else, and not double the slash. Pinned in Task 1's tests.
2. **`ASSET_BUILD_DIRECTORY` unset or empty.** Valet must behave exactly as today (`public/build`, `public/hot`). Pinned in Task 2's tests (unset and empty string, also when a value was set before).
3. **A Docker build must never write to `public/build`.** Checked by hand in Task 2 (timestamps) and Task 6 (Valet's manifest unchanged).
4. **Compose env vs `.env`.** A web request (PHP-FPM) must see the overrides too, not just CLI. `clear_env = no` was verified in `php:8.4-fpm`'s `docker.conf`. Checked in Task 5 via `/up` plus the build-docker error path, and the DB connection on login in Task 6.
5. **Data survives `docker compose down`** and is only erased by `down -v`. Checked by hand in Task 4.

---

### Task 1: `Tenant::url()` keeps the port from `APP_URL` (Stage 1a, on Valet)

**Files:**
- Modify: `app/Models/Tenant.php:195-204` (`url()`)
- Create: `tests/Feature/Tenant/TenantUrlTest.php`
- Modify: `docs/superpowers/specs/2026-10-07-docker-local-dev-design.md` (record the refinements above, set status to approved)

**Interfaces:**
- Produces: `Tenant::url(string $path = '/'): string`, unchanged signature. Now returns `scheme://{subdomain}.{central}[:port]/{path}`.

- [ ] **Step 1: Create the test file**

Run: `php artisan make:test --phpunit TenantUrlTest --no-interaction`, then move it to `tests/Feature/Tenant/TenantUrlTest.php` (namespace `Tests\Feature\Tenant`) and replace the body:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use Tests\TestCase;

class TenantUrlTest extends TestCase
{
    public function test_url_without_a_port_in_app_url(): void
    {
        config(['app.url' => 'http://tenancey.test', 'tenancy.central_domains' => ['tenancey.test']]);

        $this->assertSame('http://acme.tenancey.test/login', $this->tenant()->url('/login'));
    }

    public function test_url_keeps_the_port_from_app_url(): void
    {
        config(['app.url' => 'http://tenancey.localhost:8000', 'tenancy.central_domains' => ['tenancey.localhost']]);

        $this->assertSame('http://acme.tenancey.localhost:8000/login', $this->tenant()->url('/login'));
    }

    public function test_url_keeps_the_scheme_and_handles_paths(): void
    {
        config(['app.url' => 'https://tenancey.localhost:8443/', 'tenancy.central_domains' => ['tenancey.localhost']]);

        $this->assertSame('https://acme.tenancey.localhost:8443/', $this->tenant()->url());
        $this->assertSame('https://acme.tenancey.localhost:8443/dashboard', $this->tenant()->url('dashboard'));
    }

    private function tenant(): Tenant
    {
        return (new Tenant)->forceFill(['subdomain' => 'acme']);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `php artisan test --compact tests/Feature/Tenant/TenantUrlTest.php`
Expected: the first test passes. The other two FAIL, e.g. expected `http://acme.tenancey.localhost:8000/login`, got `http://acme.tenancey.localhost/login`.

- [ ] **Step 3: Implement**

In `app/Models/Tenant.php`, replace the body of `url()`:

```php
    /**
     * Absolute URL on the tenant's subdomain. Use this instead of url() in queued or central
     * code, where url() would point at the central domain. Scheme and port come from APP_URL.
     */
    public function url(string $path = '/'): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.$this->host().($port ? ':'.$port : '').'/'.ltrim($path, '/');
    }
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Tenant/TenantUrlTest.php`, then the full suite `php artisan test --compact`.
Expected: all pass. Valet's `APP_URL` has no port, so existing assertions such as `http://acme.tenancey.test/login` still hold.

- [ ] **Step 5: Format, and record the spec refinements**

Run: `vendor/bin/pint --dirty --format agent`.
Edit the spec: set the status to "approved"; in section 4, drop `.dockerignore` and note the `docker/php` build context; in section 2, note that nginx mounts `./public:ro` and that `node` runs under `profiles: [tools]`; in section 5, update the Stage 4/5 checks and Stage 9's `exec -e`; add the stop grace periods. These are refinements 1–6 above.

- [ ] **Step 6: Hand-off (no commit)**

Tell the developer what changed and print:
```bash
git add app/Models/Tenant.php tests/Feature/Tenant/TenantUrlTest.php docs/superpowers/specs/2026-10-07-docker-local-dev-design.md docs/superpowers/plans/2026-10-08-docker-local-dev.md
git commit -m "Keep APP_URL's port in tenant URLs"
```

---

### Task 2: Separate asset build directory (Stage 1b, on Valet)

**Files:**
- Modify: `config/app.php` (new key after `'url'`)
- Modify: `app/Providers/AppServiceProvider.php` (new public method, called from `boot()`)
- Modify: `vite.config.js`
- Modify: `.gitignore`
- Create: `tests/Feature/AssetBuildDirectoryTest.php`

**Interfaces:**
- Produces: the config key `app.asset_build_directory` (string|null, from env `ASSET_BUILD_DIRECTORY`); `AppServiceProvider::configureAssetBuildDirectory(): void`; the env var `ASSET_BUILD_DIRECTORY`, read by both Laravel and Vite. Hot file `public/{dir}.hot`.

- [ ] **Step 1: Write the failing test**

Run: `php artisan make:test --phpunit AssetBuildDirectoryTest --no-interaction`, then replace the body:

```php
<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class AssetBuildDirectoryTest extends TestCase
{
    public function test_without_the_setting_vite_uses_the_default_build_and_hot_file(): void
    {
        foreach ([null, ''] as $value) {
            config(['app.asset_build_directory' => 'build-phpunit']);
            $this->configure();

            config(['app.asset_build_directory' => $value]);
            $this->configure();

            $this->assertSame(public_path('hot'), Vite::hotFile());
        }
    }

    public function test_the_setting_moves_the_build_directory_and_hot_file(): void
    {
        config(['app.asset_build_directory' => 'build-phpunit']);
        $this->configure();

        $this->assertSame(public_path('build-phpunit.hot'), Vite::hotFile());

        $this->expectException(ViteManifestNotFoundException::class);
        $this->expectExceptionMessage(public_path('build-phpunit/manifest.json'));

        Vite::asset('resources/css/app.css');
    }

    private function configure(): void
    {
        $this->app->getProvider(AppServiceProvider::class)->configureAssetBuildDirectory();
    }
}
```

(The tests use `build-phpunit`, a folder that never exists, so they pass on Valet and in Docker alike, where the app boots with `ASSET_BUILD_DIRECTORY=build-docker` and `public/build-docker` exists from Stage 5. The first test sets a non-default value first, so it proves an unset value *resets* to the defaults rather than just leaving them alone.)

- [ ] **Step 2: Run it and see it fail**

Run: `php artisan test --compact tests/Feature/AssetBuildDirectoryTest.php`
Expected: FAIL, "Call to undefined method …configureAssetBuildDirectory()".

- [ ] **Step 3: Implement**

`config/app.php`, right after the `'url'` entry:
```php
    /*
    |--------------------------------------------------------------------------
    | Asset Build Directory
    |--------------------------------------------------------------------------
    |
    | Folder under public/ that Vite builds into. Unset means "build" (Valet).
    | Docker sets ASSET_BUILD_DIRECTORY=build-docker so the two setups never
    | overwrite each other's assets (see docs/DOCKER.md).
    |
    */

    'asset_build_directory' => env('ASSET_BUILD_DIRECTORY'),
```

`app/Providers/AppServiceProvider.php`: add `use Illuminate\Support\Facades\Vite;`, call `$this->configureAssetBuildDirectory();` in `boot()` after `DevCommands::node(...)`, and add:
```php
    /**
     * Point Laravel's Vite helper at the same build directory and hot file that vite.config.js
     * uses for ASSET_BUILD_DIRECTORY. Unset or empty (Valet) means public/build and public/hot.
     * Always sets both, so it also resets them. Public so the test can re-run it.
     */
    public function configureAssetBuildDirectory(): void
    {
        $directory = config('app.asset_build_directory') ?: 'build';

        Vite::useBuildDirectory($directory);
        Vite::useHotFile(public_path($directory === 'build' ? 'hot' : $directory.'.hot'));
    }
```

`vite.config.js`: inside the config function, before `return`:
```js
    // Docker sets ASSET_BUILD_DIRECTORY=build-docker so its build (with Docker's Reverb port baked
    // in) never overwrites Valet's public/build. Must match config('app.asset_build_directory').
    const buildDirectory = process.env.ASSET_BUILD_DIRECTORY || 'build';
```
and in `laravel({...})` add:
```js
                buildDirectory,
                hotFile: buildDirectory === 'build' ? 'public/hot' : `public/${buildDirectory}.hot`,
```

`.gitignore`: add after `/public/build`:
```
/public/build-docker
/public/build-docker.hot
```

- [ ] **Step 4: Run the tests, and check the build on the Mac**

Run: `php artisan test --compact tests/Feature/AssetBuildDirectoryTest.php`, then `php artisan test --compact`.
Expected: all pass.

Mac check (Claude runs it; this isn't a Docker command):
```bash
source ~/.nvm/nvm.sh && nvm use
ls -l public/build/manifest.json                          # note the time
npm run dev                                               # → public/build, as today
ASSET_BUILD_DIRECTORY=build-docker npm run dev            # → public/build-docker
ls public/build-docker/manifest.json && rm -rf public/build-docker
```
Expected: both builds succeed, and the second leaves `public/build` untouched. Then open `http://tenancey.test/` and a tenant page: styled, with live updates still working (Valet's Reverb on 8081).

- [ ] **Step 5: Format, notes, hand-off (no commit)**

Run: `vendor/bin/pint --dirty --format agent`.
Write `docs/docker-learning/stage-1-code-prep.md` with the teaching text: why the two changes are needed, conflicts 3.1 and 3.3 in plain words. Create `docs/DOCKER.md` with an intro (what runs where, the URLs, the never-`config:cache` rule) and a "Stage 1" section.
Print:
```bash
git add config/app.php app/Providers/AppServiceProvider.php vite.config.js .gitignore tests/Feature/AssetBuildDirectoryTest.php docs/DOCKER.md
git commit -m "Allow a separate Vite build directory via ASSET_BUILD_DIRECTORY"
```

---

### Task 3: Our PHP image (Stage 2)

**Files:**
- Create: `docker/php/Dockerfile`
- Create: `docker/php/php.ini`

**Interfaces:**
- Produces: image `tenancey-php:dev` (built from context `docker/php`). It has `php`, `php-fpm` (listening on 9000), `composer`, and the extensions `pdo_mysql`, `pcntl`, `posix`, `redis`. `WORKDIR /var/www/html`.

- [ ] **Step 1: Write `docker/php/Dockerfile`**

```dockerfile
# Local-development image for tenancey: PHP-FPM plus what the app's packages need.
# One image runs four services (see compose.yaml): app (php-fpm), horizon, reverb, scheduler.
FROM php:8.4-fpm

# git and unzip let Composer download and unpack packages.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Bundled extensions: pdo_mysql (MySQL), pcntl (signals for Horizon and Reverb).
# posix is already compiled in.
RUN docker-php-ext-install pdo_mysql pcntl

# phpredis is not bundled with PHP, so it comes from PECL. Same version as the Mac.
RUN pecl install redis-6.3.0 \
    && docker-php-ext-enable redis

# Copy the composer binary out of the official Composer image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Our dev settings. "zz-" sorts last, so these override earlier .ini files.
COPY php.ini /usr/local/etc/php/conf.d/zz-tenancey.ini

WORKDIR /var/www/html
```

- [ ] **Step 2: Write `docker/php/php.ini`**

```ini
; Local development settings for the tenancey PHP image.
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 20M
display_errors = On
error_reporting = E_ALL
```

- [ ] **Step 3: Teach and hand the commands over**

Explain every Dockerfile line: `FROM`, `RUN` (one layer each, why `&&` and `rm -rf /var/lib/apt/lists/*`), `COPY --from` (a multi-stage copy), `ENV`, `COPY`, `WORKDIR`, the build context (`docker/php`), and the layer cache (re-run the build and see `CACHED`).

Developer runs:
```bash
docker build -t tenancey-php:dev docker/php
docker images tenancey-php
docker run --rm tenancey-php:dev php -m | grep -E 'pdo_mysql|pcntl|posix|redis'
docker run --rm tenancey-php:dev composer --version
docker run --rm -v "$PWD":/var/www/html tenancey-php:dev composer check-platform-reqs
docker build -t tenancey-php:dev docker/php        # second time: every step CACHED
docker history tenancey-php:dev                    # our layers on top of php:8.4-fpm's
```
Expected: all four extensions are listed; Composer 2.x; every `check-platform-reqs` line says `success`; the second build finishes in seconds.

- [ ] **Step 4: Notes, hand-off (no commit)**

Write `docs/docker-learning/stage-2-php-image.md` (the verbatim teaching text) and the "Stage 2" section of `docs/DOCKER.md`. Update the README tables (`docker build`, `-t`, `docker history`).
Print: `git add docker/php docs/DOCKER.md` and `git commit -m "Add the local-development PHP image"`.

---

### Task 4: Compose with `mysql` and `redis` (Stage 3)

**Files:**
- Create: `compose.yaml`
- Create: `docker/mysql/init/01-databases.sql`

**Interfaces:**
- Produces: Compose project `tenancey`; services `mysql` (host `mysql:3306` inside the network, `127.0.0.1:3307` from the Mac, root/`docker`) and `redis` (`redis:6379`); named volumes `mysql-data`, `redis-data`; databases `tenancey` and `tenancey_testing`.

- [ ] **Step 1: Write `docker/mysql/init/01-databases.sql`**

```sql
-- Runs once, when the mysql-data volume is empty (the first start, or after `down -v`).
CREATE DATABASE IF NOT EXISTS `tenancey` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tenancey_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

- [ ] **Step 2: Write `compose.yaml` (first version)**

```yaml
# Local development for tenancey. Guide: docs/DOCKER.md
# App: http://tenancey.localhost:8000   Tenants: http://{subdomain}.tenancey.localhost:8000
name: tenancey

services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: docker # local dev only; matches DB_PASSWORD below
    ports:
      - "3307:3306" # Mac 3307 -> container 3306 (the Mac's own MySQL keeps 3306)
    volumes:
      - mysql-data:/var/lib/mysql
      - ./docker/mysql/init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-uroot", "-pdocker"]
      interval: 5s
      timeout: 5s
      retries: 30
    restart: unless-stopped

  redis:
    image: redis:7.4-alpine
    volumes:
      - redis-data:/data
    restart: unless-stopped

volumes:
  mysql-data:
  redis-data:
```

- [ ] **Step 3: Teach and hand the commands over**

Explain: services; `image`; `environment`; `ports`; the named volume vs the bind mount; `:ro`; `/docker-entrypoint-initdb.d`; `healthcheck`; `restart`; the default network and service-name DNS; why redis publishes no port.

Developer runs:
```bash
docker compose config                 # Compose's view of the file (validates it)
docker compose up -d
docker compose ps                     # mysql "healthy" after ~20s, redis "running"
docker compose logs mysql | tail -20
docker compose exec mysql mysql -uroot -pdocker -e 'SHOW DATABASES'
docker compose exec redis redis-cli ping            # PONG
docker volume ls                                     # tenancey_mysql-data, tenancey_redis-data
docker compose exec mysql mysql -uroot -pdocker -e 'CREATE TABLE tenancey.probe (id INT)'
docker compose down && docker compose up -d
docker compose exec mysql mysql -uroot -pdocker -e 'SHOW TABLES FROM tenancey'   # probe survived
docker compose exec mysql mysql -uroot -pdocker -e 'DROP TABLE tenancey.probe'
```
Also connect a DB GUI to `127.0.0.1:3307`, user `root`, password `docker`. Explain (without running it yet) that `docker compose down -v` deletes the volumes, and the init SQL then runs again.

Expected: `tenancey` and `tenancey_testing` are listed; `probe` survives `down`/`up`.

- [ ] **Step 4: Notes, hand-off (no commit)**

`docs/docker-learning/stage-3-compose-mysql-redis.md`, the `docs/DOCKER.md` "Stage 3" section, the README tables (`compose up -d/ps/logs/exec/down/config`, `volume ls`).
Print: `git add compose.yaml docker/mysql docs/DOCKER.md` and `git commit -m "Add Compose with MySQL and Redis"`.

---

### Task 5: `app` + `nginx` (Stage 4)

**Files:**
- Modify: `compose.yaml` (the `x-laravel-env` and `x-php` anchors; the `app` and `nginx` services)
- Create: `docker/nginx/default.conf`

**Interfaces:**
- Consumes: image `tenancey-php:dev` (Task 3), `mysql`/`redis` (Task 4), `app.asset_build_directory` (Task 2).
- Produces: the anchors `&laravel-env` and `&php`, reused by `horizon`/`reverb`/`scheduler` in later tasks; `app:9000` (FastCGI); the Mac's `:8000`.

- [ ] **Step 1: Write `docker/nginx/default.conf`**

```nginx
# One server for the central domain and every tenant subdomain: Laravel tells them apart
# by the Host header (CENTRAL_DOMAIN vs {subdomain}.CENTRAL_DOMAIN).
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP goes to PHP-FPM in the app container, port 9000.
    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 2: Add the anchors and services to `compose.yaml`**

Insert after `name: tenancey`:
```yaml
# Docker-only settings. They win over .env because Laravel never overwrites a variable that is
# already set in the environment. Secrets (APP_KEY, TENANT_DB_KEY, REVERB_APP_*) stay in .env.
x-laravel-env: &laravel-env
  APP_URL: http://tenancey.localhost:8000
  CENTRAL_DOMAIN: tenancey.localhost
  DB_HOST: mysql
  DB_PORT: "3306"
  DB_USERNAME: root
  DB_PASSWORD: docker
  REDIS_HOST: redis
  REDIS_PORT: "6379"
  MAIL_HOST: mailpit
  MAIL_PORT: "1025"
  REVERB_HOST: reverb
  REVERB_PORT: "8080"
  REVERB_SCHEME: http
  REVERB_SERVER_HOST: 0.0.0.0
  REVERB_SERVER_PORT: "8080"
  ASSET_BUILD_DIRECTORY: build-docker

# Shared by the four PHP services: same image, same code, same settings.
x-php: &php
  build: docker/php
  image: tenancey-php:dev
  volumes:
    - .:/var/www/html
  environment: *laravel-env
  depends_on:
    mysql:
      condition: service_healthy
    redis:
      condition: service_started
  restart: unless-stopped
```
Add under `services:`:
```yaml
  app:
    <<: *php # PHP-FPM, the image's default command

  nginx:
    image: nginx:1.30-alpine
    ports:
      - "8000:80"
    volumes:
      - ./public:/var/www/html/public:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app
    restart: unless-stopped
```

- [ ] **Step 3: Teach and hand the commands over**

Explain: YAML anchors (`&`, `*`, `<<:`); env precedence (Compose env → process env → dotenv immutable); `build` + `image`; `depends_on` with `condition`; FastCGI; why nginx only needs `public`; `$realpath_root`; `exec` vs `run`.

Developer runs:
```bash
docker compose up -d --build
docker compose ps
docker compose exec app php artisan about | head -30      # URL/DB/cache drivers as seen in Docker
docker compose exec app php artisan config:show database.connections.mysql.host   # mysql
docker compose exec app php artisan migrate --seed --no-interaction
curl -i http://tenancey.localhost:8000/up                  # HTTP/1.1 200
open http://tenancey.localhost:8000/login
docker compose logs app nginx | tail -30
```
Expected: migrations run against the Docker MySQL; `/up` returns 200; `/login` shows Laravel's error page "Vite manifest not found at: /var/www/html/public/build-docker/manifest.json". That error is correct for now: it proves the web request ran in Docker and read `ASSET_BUILD_DIRECTORY`. Also check `ls -la storage/logs` on the Mac: files written from the container are owned by the Mac user.

- [ ] **Step 4: Notes, hand-off (no commit)**

`docs/docker-learning/stage-4-app-nginx.md`, `docs/DOCKER.md` "Stage 4" (including the `config:cache` rule), the README tables.
Print: `git add compose.yaml docker/nginx docs/DOCKER.md` and `git commit -m "Add the app and nginx services"`.

---

### Task 6: `node` asset build (Stage 5)

**Files:**
- Modify: `compose.yaml` (the `node` service, the `node-modules` volume)

**Interfaces:**
- Consumes: `ASSET_BUILD_DIRECTORY` support in `vite.config.js` (Task 2).
- Produces: `public/build-docker/manifest.json`, with Reverb `localhost:8082` baked in.

- [ ] **Step 1: Add the service and volume**

Under `services:`:
```yaml
  # One-off asset build: docker compose run --rm node
  node:
    image: node:22.12-bookworm-slim
    profiles: [tools] # not started by `docker compose up`
    working_dir: /var/www/html
    command: sh -c "npm ci && npm run dev"
    environment:
      ASSET_BUILD_DIRECTORY: build-docker
      VITE_REVERB_HOST: localhost # the browser reaches Reverb on the Mac's port 8082
      VITE_REVERB_PORT: "8082"
      VITE_REVERB_SCHEME: http
    volumes:
      - .:/var/www/html
      - node-modules:/var/www/html/node_modules # Linux packages; the Mac's node_modules stays untouched
```
Under `volumes:` add `node-modules:`.

- [ ] **Step 2: Teach and hand the commands over**

Explain: profiles; `run --rm` vs `up`; a volume mounted over a bind-mount subfolder; native binaries per OS; build-time `VITE_*` vs run-time env.

Developer runs:
```bash
ls -l public/build/manifest.json                  # Valet's build: note the time
docker compose run --rm node
ls public/build-docker                            # manifest.json, assets/
ls -l public/build/manifest.json                  # same time as before: untouched
grep -o '8082' public/build-docker/assets/*.js | head -1
open http://tenancey.localhost:8000/login         # styled page
```
Then log in as `admin@tenancey.test` / `password`. Open `http://tenancey.test/` too: still styled, still Valet.

Expected: the Docker login works and pages are styled; Valet is unchanged.

- [ ] **Step 3: Notes, hand-off (no commit)**

`docs/docker-learning/stage-5-node.md`, `docs/DOCKER.md` "Stage 5" ("after changing JS/CSS: `docker compose run --rm node`"), the README tables.
Print: `git add compose.yaml docs/DOCKER.md` and `git commit -m "Add the node asset build service"`.

---

### Task 7: `horizon` + `mailpit` (Stage 6)

**Files:**
- Modify: `compose.yaml`

**Interfaces:**
- Consumes: `&php` (Task 5); the fixed `Tenant::url()` (Task 1).
- Produces: queue workers in Docker; Mailpit SMTP at `mailpit:1025` and its inbox at `http://localhost:8025`.

- [ ] **Step 1: Add the services**

```yaml
  horizon:
    <<: *php
    command: php artisan horizon
    stop_grace_period: 660s # must exceed the longest job timeout (600s), like supervisor's stopwaitsecs

  mailpit:
    image: axllent/mailpit:v1.29
    ports:
      - "8025:8025" # web inbox; SMTP (1025) stays inside the network
    restart: unless-stopped
```

- [ ] **Step 2: Teach and hand the commands over**

Explain: overriding `command`; long-running containers; `restart: unless-stopped` replacing supervisor; `stop_grace_period` and SIGTERM; logs per service; restarting after code changes (`docker compose restart horizon`).

Developer runs:
```bash
docker compose up -d
docker compose ps
docker compose logs -f horizon        # Ctrl+C to stop following
```
In the browser, at `http://tenancey.localhost:8000`, create a tenant `acme` (tick "dedicated database user" once, on a second tenant). Watch it reach Ready (refresh, since Reverb arrives in Stage 7). Open `http://localhost:8025`: the welcome mail links to `http://acme.tenancey.localhost:8000/login`. Log in there with the mailed credentials. Also check `http://tenancey.localhost:8000/horizon`.
```bash
docker compose exec mysql mysql -uroot -pdocker -e "SHOW DATABASES LIKE 'tenancey\_%'"
docker compose restart horizon && docker compose logs --tail=5 horizon
```
Expected: provisioning finishes, the mail arrives in Mailpit with the `:8000` link, and the tenant login works. Valet's tenants are untouched (different MySQL).

- [ ] **Step 3: Notes, hand-off (no commit)**

`docs/docker-learning/stage-6-horizon-mailpit.md`, `docs/DOCKER.md` "Stage 6" (`docker compose restart horizon` replaces `horizon:terminate`), the README tables.
Print: `git add compose.yaml docs/DOCKER.md` and `git commit -m "Add Horizon and Mailpit services"`.

---

### Task 8: `reverb` (Stage 7)

**Files:**
- Modify: `compose.yaml`

**Interfaces:**
- Consumes: `REVERB_*` in `&laravel-env` (the server publishes to `reverb:8080`); `VITE_REVERB_*` baked in by Task 6 (the browser connects to `localhost:8082`).

- [ ] **Step 1: Add the service**

```yaml
  reverb:
    <<: *php
    command: php artisan reverb:start
    ports:
      - "8082:8080" # the browser connects to localhost:8082; PHP containers use reverb:8080
    stop_grace_period: 30s
```

- [ ] **Step 2: Teach and hand the commands over**

Explain: the two paths to Reverb (container to container by service name, browser through the published port); build-time vs run-time config; why the port numbers differ.

Developer runs:
```bash
docker compose up -d
docker compose logs reverb
```
Open the tenant list at `http://tenancey.localhost:8000/tenants` and create `globex`. The row appears and its steps update live, without refreshing. Open its page: steps tick live. Delete a tenant: teardown updates live. In DevTools > Network > WS, the socket goes to `ws://localhost:8082/app/…`. Repeat once on `http://tenancey.test/tenants`: Valet still updates live via 8081.

Expected: live updates in both setups.

- [ ] **Step 3: Notes, hand-off (no commit)**

`docs/docker-learning/stage-7-reverb.md`, `docs/DOCKER.md` "Stage 7" (`docker compose restart reverb` replaces `reverb:restart`), the README tables.
Print: `git add compose.yaml docs/DOCKER.md` and `git commit -m "Add the Reverb service"`.

---

### Task 9: `scheduler` (Stage 8)

**Files:**
- Modify: `compose.yaml`

- [ ] **Step 1: Add the service**

```yaml
  scheduler:
    <<: *php
    command: php artisan schedule:work # runs due tasks every minute; replaces a cron entry
```

- [ ] **Step 2: Teach and hand the commands over**

Explain: why containers usually have no cron; `schedule:work`.

Developer runs:
```bash
docker compose up -d
docker compose logs scheduler
docker compose exec scheduler php artisan schedule:list   # model:prune, daily
docker compose ps                                          # 7 services up
```
Expected: the scheduler is running and `schedule:list` shows `model:prune`.

- [ ] **Step 3: Notes, hand-off (no commit)**

`docs/docker-learning/stage-8-scheduler.md`, `docs/DOCKER.md` "Stage 8".
Print: `git add compose.yaml docs/DOCKER.md` and `git commit -m "Add the scheduler service"`.

---

### Task 10: Tests in Docker (Stage 9)

**Files:** none (commands and docs only).

- [ ] **Step 1: Teach and hand the commands over**

Explain: `exec -e` sets process env, which beats both the Compose env and `.env`; why the tests need `tenancey.test` hosts; phpunit.xml still switches to `tenancey_testing` and the test prefixes (it doesn't set `DB_HOST`, so the tests use the Docker MySQL).

Developer runs:
```bash
docker compose exec -e APP_URL=http://tenancey.test -e CENTRAL_DOMAIN=tenancey.test app php artisan test --compact
```
Expected: all tests pass against the container's MySQL and Redis (DBs 13–15). If anything fails, use systematic debugging before changing anything; a failure here is a Docker/env difference, not a reason to edit tests.

- [ ] **Step 2: Notes, hand-off**

`docs/docker-learning/stage-9-tests.md`, `docs/DOCKER.md` "Stage 9". Print: `git add docs/DOCKER.md` and `git commit -m "Document running tests in Docker"`.

---

### Task 11: Wrap-up (Stage 10)

**Files:**
- Modify: `docs/DOCKER.md` (daily commands, reset, troubleshooting)
- Modify: `CLAUDE.md` (Environment: the Docker setup and the `config:cache` rule; Reference docs: `docs/DOCKER.md`)
- Modify: `README.md` (short "Run with Docker" section linking `docs/DOCKER.md`)

- [ ] **Step 1: Write the wrap-up section of `docs/DOCKER.md`**

It must contain:
- **Daily:** `docker compose up -d`, `docker compose ps`, `docker compose logs -f <service>`, `docker compose exec app php artisan …`, `docker compose run --rm node`, `docker compose restart horizon reverb`, `docker compose stop`/`down`.
- **Reset Docker data:** delete tenants in the UI first, then `docker compose down -v` (erases only Docker's MySQL/Redis/node_modules volumes; never the Mac's).
- **Troubleshooting:**
  - "port is already allocated" → `lsof -i :<port>`
  - "Vite manifest not found …build-docker" → run the node build
  - stale `public/build-docker.hot` → delete it
  - wrong host or DB in Docker → someone ran `config:cache`: `php artisan config:clear`
  - slow pages → bind-mount note (spec section 7)

- [ ] **Step 2: Update `CLAUDE.md` and `README.md`**

`CLAUDE.md` Environment: one bullet saying the Docker setup at `http://tenancey.localhost:8000` lives in `compose.yaml` (see `docs/DOCKER.md`), plus the never-`config:cache`/`optimize` rule. Reference docs list: add `docs/DOCKER.md`.

- [ ] **Step 3: Final check and hand-off**

The developer runs `docker compose down && docker compose up -d` and repeats the Stage 6 and 7 browser checks quickly. Claude runs `php artisan test --compact` on Valet.
Write `docs/docker-learning/stage-10-wrap-up.md`.
Print: `git add docs/DOCKER.md CLAUDE.md README.md` and `git commit -m "Document the Docker local-development setup"`.
