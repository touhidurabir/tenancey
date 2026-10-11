# Docker for local development: design

Date: 2026-10-07. Status: **approved**. Refined while planning (2026-10-08), see section 8.

## 1. Goal and constraints

**Goal.** Run the whole app (web, queues, WebSockets, scheduler, database, cache, mail, asset
build) in Docker containers for local development. **The main purpose is learning Docker**,
so the work is split into small stages. Each stage adds one idea, is explained line by line,
and ends with a check the developer runs by hand before the next stage starts.

**Decided:**
- **Local development first.** A production image is a later, separate phase.
- **Side by side with Valet.** The Docker copy is reached at `http://tenancey.localhost:8000`
  and `http://{subdomain}.tenancey.localhost:8000`. Browsers resolve `*.localhost` to the
  machine itself, so no DNS setup is needed. Valet (`tenancey.test`), the supervisor programs,
  and other projects on this machine keep running and are never touched.
- **Own service containers.** MySQL, Redis and a mail catcher run in containers with their
  own data. Docker tenants and Valet tenants never share a database.
- **Approach A: built from official images.** One custom PHP image (from `php:8.4-fpm`),
  plus the official nginx, mysql, redis, node and mailpit images. No Sail and no all-in-one
  images, so nothing is hidden.

**Out of scope:** the production image, TLS, CI, and the React frontend.

**Machine:** Intel Mac (x86_64), Docker Desktop 29.8, Docker Compose v5.5.

## 2. Services

| Service | Image | Command / role | Published to the Mac |
|---|---|---|---|
| `nginx` | `nginx:1.30-alpine` | Web server; sends `.php` requests to `app:9000`. Mounts only `./public`, read-only | `8000:80` |
| `app` | ours (`docker/php/Dockerfile`) | PHP-FPM: one Laravel run per web request | none |
| `horizon` | ours, same image | `php artisan horizon`; `stop_grace_period: 660s` | none |
| `reverb` | ours, same image | `php artisan reverb:start` (listens on 8080 inside); `stop_grace_period: 30s` | `8082:8080` |
| `scheduler` | ours, same image | `php artisan schedule:work` | none |
| `mysql` | `mysql:8.4` | Landlord DB `tenancey`, test DB `tenancey_testing`, tenant DBs | `3307:3306` (for a DB GUI) |
| `redis` | `redis:7.4-alpine` | Queues, cache, sessions | none |
| `mailpit` | `axllent/mailpit:v1.29` | Catches outgoing mail; web inbox | `8025:8025` (inbox) |
| `node` | `node:22.12-bookworm-slim` (matches `.nvmrc`) | `npm ci && npm run dev`, then exits. Profile `tools`: run with `docker compose run --rm node` | none |

- All services share Compose's default network and reach each other **by service name**
  (`mysql`, `redis`, `mailpit`, `reverb`, `app`).
- The four PHP services use `restart: unless-stopped`. This replaces supervisor: Docker brings
  a crashed process back.
- Host ports are chosen to avoid what the Mac already uses: 80 (Valet), 3306 (MySQL),
  6379 (Redis), 8080 (another project's Reverb), 8081 (this project's Valet Reverb),
  2525 (HELO).

### Volumes

| Mount | Kind | Used by | Why |
|---|---|---|---|
| project folder → `/var/www/html` | bind mount | `app`, `horizon`, `reverb`, `scheduler`, `node` (nginx gets `./public` only, read-only) | Edits on the Mac show up at once, with no rebuild |
| `mysql-data` → `/var/lib/mysql` | named volume | `mysql` | Data survives `docker compose down`; erased only by `down -v` |
| `redis-data` → `/data` | named volume | `redis` | Same |
| `node-modules` → `/var/www/html/node_modules` | named volume | `node` | Linux binaries; covers the Mac's `node_modules` (conflict 3) |

### MySQL credentials

- The official image needs a root password, so Compose sets `MYSQL_ROOT_PASSWORD` and the
  PHP services get a matching `DB_PASSWORD` override. The Mac's empty root password is
  unaffected.
- The init step creates the `tenancey` and `tenancey_testing` databases on first start, from a
  small SQL file in `docker/mysql/init/`.
- Tenant DB users are created as `'name'@'%'` (`MySqlDatabaseUserManager::account()`). `%`
  matches connections from another container, so dedicated tenant users work without any
  change.

## 3. Conflicts with the Valet setup, and their fixes

Docker and Valet read the **same project folder**, so anything stored in a file is shared.

### 3.1 `Tenant::url()` drops the port (code change)

`Tenant::url()` builds `scheme://host/path` and ignores any port in `APP_URL`. With
`APP_URL=http://tenancey.localhost:8000`, tenant links (login link, welcome mail, broadcast
`login_url`) would point at port 80.

**Fix:** also read the port from `APP_URL` and add `:port` when present. Valet's `APP_URL`
has no port, so its links are unchanged. Covered by a test with and without a port.

### 3.2 Shared `.env` (no code change)

Laravel loads `.env` in immutable mode: a variable already set in the process environment
wins over the file. Compose sets the Docker-only values on the PHP services through one YAML
anchor (`x-laravel-env`), so they're written once:

```
APP_URL=http://tenancey.localhost:8000      CENTRAL_DOMAIN=tenancey.localhost
DB_HOST=mysql   DB_PORT=3306   DB_PASSWORD=<docker root password>
REDIS_HOST=redis   REDIS_PORT=6379
MAIL_HOST=mailpit   MAIL_PORT=1025
REVERB_HOST=reverb   REVERB_PORT=8080   REVERB_SERVER_PORT=8080   (server side)
ASSET_BUILD_DIRECTORY=build-docker          (see 3.3)
```

- Secrets (`APP_KEY`, `TENANT_DB_KEY`, `REVERB_APP_*`) stay only in `.env`.
- `APP_ENV` stays `local`: `HorizonServiceProvider` lets anyone in when the environment is local.
- **Rule: never run `php artisan config:cache` (or `optimize`) in either setup.** The cache
  file `bootstrap/cache/config.php` is shared and would force one setup's hosts onto the
  other. Stated in `docs/DOCKER.md`.
- Compose also reads `.env`, but only to fill `${...}` placeholders inside `compose.yaml`.
  It never passes those values into containers by itself.

### 3.3 Shared `public/build` and `public/hot` (code change)

`VITE_*` values (Echo's Reverb host and port) are baked into the JS at build time. Valet's
build points at port 8081 and Docker's must point at 8082. With one shared `public/build`,
whichever setup built last breaks the other's live updates. `public/hot` has the same issue.

**Fix:**
- `vite.config.js` passes `process.env.ASSET_BUILD_DIRECTORY ?? 'build'` as the
  laravel-vite-plugin `buildDirectory` (and a matching hot file).
- Laravel reads a config key fed by the same variable. In `AppServiceProvider` it calls
  `Vite::useBuildDirectory()` / `Vite::useHotFile()` only when the variable is set.
- The `node` service sets `ASSET_BUILD_DIRECTORY=build-docker`, `VITE_REVERB_HOST=localhost`
  and `VITE_REVERB_PORT=8082`. Process environment variables win over `.env` in Vite too.
- `public/build-docker` is added to `.gitignore`.

Unset (Valet), everything behaves exactly as today. Routing WebSockets through nginx
(same host and port as the page) was considered and deferred to the production phase,
because it would also need Valet's nginx changed.

### 3.4 Mac `node_modules` in a Linux container (no code change)

Vite, rollup, esbuild, lightningcss and Tailwind's oxide ship platform-specific native
binaries. The `node` service mounts the `node-modules` named volume over
`/var/www/html/node_modules`, so it installs and uses its own Linux copy. The Mac's folder is
never touched. `vendor/` is plain PHP and stays shared.

### 3.5 Shared and accepted

- **`storage/logs`:** both setups write to the same files. Lines can be told apart by host or
  tenant uuid.
- **Tenant storage folders:** named by uuid, so the two setups can't collide.
- **Compiled Blade views:** named by a hash of the full path. The path inside the container
  differs, so each setup gets its own files.

## 4. Files to be added or changed

| File | New / changed | Purpose |
|---|---|---|
| `compose.yaml` | new | All services, ports, volumes, environment overrides |
| `docker/php/Dockerfile` | new | `php:8.4-fpm` + required extensions + Composer |
| `docker/php/php.ini` | new | Dev PHP settings (memory, upload size, errors shown) |
| `docker/nginx/default.conf` | new | Wildcard `server_name`, `root /var/www/html/public`, FastCGI to `app:9000` |
| `docker/mysql/init/01-databases.sql` | new | Creates `tenancey` and `tenancey_testing` on first start |
| `app/Models/Tenant.php` | changed | Port in `url()` (3.1) |
| `vite.config.js`, `app/Providers/AppServiceProvider.php`, a config key | changed | Separate build directory (3.3) |
| `.gitignore` | changed | `public/build-docker` |
| `docs/DOCKER.md` | new | The learning doc, grown one section per stage |
| `CLAUDE.md`, `README.md` | changed | Point to the Docker setup and the `config:cache` rule |

`docker/` is a new top-level folder. Approving this spec approves it.

The PHP extension list is **not guessed**. In stage 2 we run `composer check-platform-reqs`
inside the image and add exactly what it reports, plus `pcntl`/`posix` for Horizon and Reverb.

## 5. Learning stages

The way of working in every stage:
1. Claude writes the files for that stage only and explains every line.
2. **The developer runs the Docker commands by hand.** Claude prints them; it does not run
   them, so the commands become familiar.
3. The developer runs the stage's check. The next stage starts only when the check passes.
4. Claude adds that stage's section to `docs/DOCKER.md`.

| # | Stage | New ideas | Check |
|---|---|---|---|
| 0 | Concepts, no files | image vs container, `docker run`, `ps`, `images`, `logs`, `rm` | `docker run --rm php:8.4-cli php -v` prints PHP 8.4 |
| 1 | Code prep on Valet (3.1, 3.3) | none (plain Laravel) | Tests pass; Valet pages and live updates unchanged |
| 2 | Our PHP image | Dockerfile, layers, `docker build`, tags, `.dockerignore` | `php -m` in the image lists the extensions; `composer check-platform-reqs` is green |
| 3 | `compose.yaml` with `mysql` + `redis` | services, named volumes, published ports, `up -d`/`down`/`down -v` | DB GUI connects on 3307; both databases exist; data survives `down`/`up` |
| 4 | `app` + `nginx` | bind mounts, service-name DNS, env overrides, `exec` | `docker compose exec app php artisan migrate --seed`; `/up` returns 200; `/login` shows "Vite manifest not found at …/build-docker/manifest.json" (assets come in stage 5) |
| 5 | `node` | one-off containers, profiles, the volume-over-bind-mount trick | `public/build-docker` exists; central login works at `tenancey.localhost:8000`, pages styled; Valet's `public/build` untouched |
| 6 | `horizon` + `mailpit` | long-running workers, `restart`, logs per service | Create a tenant in Docker: provisioning finishes, mail in Mailpit, tenant login on `acme.tenancey.localhost:8000` |
| 7 | `reverb` | a second published port, build-time vs run-time config | Tenant page and list update live in Docker; Valet's still do too |
| 8 | `scheduler` | `schedule:work` instead of cron | `docker compose logs scheduler` shows it running |
| 9 | Tests in Docker | `exec` with a different env | `docker compose exec -e APP_URL=http://tenancey.test -e CENTRAL_DOMAIN=tenancey.test app php artisan test`: all pass against the container MySQL |
| 10 | Wrap-up | daily commands, reset, troubleshooting | `docs/DOCKER.md` complete; CLAUDE.md/README updated |

## 6. Testing

- Stage 1's code changes are covered by PHPUnit (`Tenant::url()` with and without a port) and
  run on Valet as today.
- Stage 9 runs the full suite inside Docker. `phpunit.xml`'s test settings still apply; only
  hosts and the password change through the Compose environment.
- Each stage's manual check is the acceptance test for its Docker files.

## 7. Risks

- **Bind-mount speed** on macOS. Docker Desktop's VirtioFS is usually fine for one dev app. If
  pages are slow, the fallback (documented, not built) is to mark `vendor/` as a cached mount.
- **File ownership.** PHP-FPM runs as `www-data` in the container. Docker Desktop maps writes
  to the Mac user, so `storage/` and `bootstrap/cache` stay writable. Checked in stage 4.
- **Port clashes.** If a published port is taken, `up` fails with "port is already allocated".
  The table in section 2 lists the free ports chosen.

## 8. Refinements made while planning (2026-10-08)

1. **No `.dockerignore`.** The image's build context is `docker/php/`, which holds only the
   Dockerfile and `php.ini`, so the project is never sent to the builder. A `.dockerignore`
   comes back in the production phase, when the image copies the code.
2. **nginx mounts only `./public`, read-only.** It serves static files and passes PHP to `app`.
3. **`node` runs on demand** (`docker compose run --rm node`, profile `tools`), so
   `docker compose up` doesn't re-run `npm ci`.
4. **Stage 4's check** expects the "Vite manifest not found …/build-docker/manifest.json" page,
   which proves the web request ran in Docker and read `ASSET_BUILD_DIRECTORY`. Logging in moves
   to stage 5.
5. **Tests in Docker** pass `-e APP_URL=http://tenancey.test -e CENTRAL_DOMAIN=tenancey.test`.
   The tests assert `tenancey.test` hosts, and phpunit.xml's `force` can't override Compose's
   values: it doesn't touch `$_SERVER`, which Laravel reads first.
6. **Stop grace periods:** `horizon` 660s (above the longest job timeout, 600s) and `reverb` 30s,
   the Compose counterparts of supervisor's `stopwaitsecs`.
7. **Asset build directory** lives in config key `app.asset_build_directory`. The hot file for a
   non-default directory is `public/{dir}.hot`.
