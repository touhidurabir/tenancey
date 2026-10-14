# Tenancey

A working starting point for a **multi-tenant, multi-database Laravel app**, built on
[stancl/tenancy](https://tenancyforlaravel.com/) v3.

- Each tenant gets its own MySQL database, storage folder and Redis prefix.
- Each tenant is served from its own subdomain: `acme.yourapp.test`.
- A central (landlord) admin creates tenants, watches them being provisioned, and can disable,
  retry, delete or sign in as them.

Clone it, set it up, and build your own app on top of it, or read it to see how the pieces fit.

> [!IMPORTANT]
> **Tenancey is still in active development, but it is ready to use today as a boilerplate for a
> new multi-tenant app.** The tenancy core works end to end and is covered by feature tests:
> subdomain routing, a database per tenant, queued provisioning and teardown, separate auth,
> impersonation, and audit logging.
>
> Expect changes while it matures:
> - A React + TypeScript frontend is planned to replace the Blade UI.
> - Real-time updates (Laravel Reverb) are planned; progress pages currently reload every few seconds.
> - Internals may be reorganised between versions.
>
> If you start a project from it, treat your copy as your own code: there is no upgrade path from
> one Tenancey version to the next. Review the security-sensitive parts (database user
> privileges, the seeded admin, `TENANT_DB_KEY`) before going to production.

## Features

- **Subdomain tenancy, without a `domains` table.**
  - Each tenant has one `subdomain` column, read by a custom resolver.
  - Unknown or deleted subdomains get a "No such workspace" page.
- **Database per tenant** (`{prefix}{uuid}`).
  - Optionally a **dedicated MySQL user** per tenant: generated or typed, with the password
    stored encrypted.
- **Queued provisioning pipeline** (`Bus::chain`):
  1. Create the database (and, optionally, its user).
  2. Migrate and seed it.
  3. Prepare storage, and create the tenant's file bucket.
  4. Create the first admin user.
  5. Mark the tenant Ready.
  6. Email the credentials.

  Every step is idempotent, and a failed run can be retried from the admin UI.
- **Queued teardown** drops the database, the user, storage, the bucket and Redis keys, then soft-deletes the
  row. The soft-deleted row is the record, so its subdomain stays reserved.
- **Tenant lifecycle:** `enabled` (the admin's on/off switch) plus `state` (Provisioning, Ready,
  Failed, Deleting, Deleted). Users get in only when the tenant is enabled **and** Ready.
- **Separate auth for each side:**
  - Central admins: `CentralUser`, guard `central`.
  - Tenant users: `User`, guard `web`, with roles from spatie/laravel-permission stored in the
    tenant database.
- **Optional forced password change** on the tenant admin's first sign-in.
- **Impersonation:**
  - The central admin signs in as a tenant's first admin through a one-time, 60-second link.
  - The session shows a banner and ends after 60 minutes.
  - Every step is audited.
- **Append-only audit log** (`/audit`), covering:
  - Central sign-in, failed sign-in and sign-out.
  - Tenant create, rename, enable/disable, retry and delete.
  - Each impersonation step.
- **Structured logging:**
  - Provisioning and teardown write to a daily `tenancy` log.
  - Each line is tagged with the tenant uuid, the run and the step.
  - Read the logs in [log-viewer](https://github.com/opcodesio/log-viewer) at `/log-viewer`.
- **One file bucket per tenant**, chosen by one setting: `FILESYSTEM_DISK` = `local`, `minio` or
  `s3`. Tenant users get an avatar and "My files" (spatie/laravel-medialibrary), downloaded through
  5-minute signed links.
- **Horizon** runs the queues, with separate tenant-aware, central and provisioning connections.
- **Feature tests** run against real MySQL and provision real tenant databases.

## Requirements

Local development runs entirely in Docker (`compose.yaml`): PHP-FPM, nginx, MySQL, Redis, Horizon,
Reverb, the scheduler, Mailpit and MinIO. On your machine you need only:

| Requirement | Notes |
|---|---|
| Docker Desktop with Compose v2 | Developed and checked on Docker Desktop 4.94 (macOS). docker-edge mode needs Compose 2.24+ (`!reset`). Native Docker Engine on Linux is untested: the PHP containers write to the bind-mounted `storage/` as different users, which may need `chmod` there |
| bash | Runs `bin/setup`. macOS and Linux have it; on Windows use WSL |
| Chrome or Firefox | They resolve `*.localhost` (tenant addresses) by themselves. Safari can't |

**Optional: HTTPS on `tenancey.test`** instead of `localhost` ports. This needs a Traefik proxy on
an external Docker network named `edge`, a trusted certificate (e.g. [mkcert](https://github.com/FiloSottile/mkcert))
and DNS for `*.tenancey.test`. See [docs/DOCKER.md](docs/DOCKER.md#two-modes).

What runs in the containers:

| Component | Version | Notes |
|---|---|---|
| PHP | **8.4** | `docker/php/`: `pdo_mysql`, `redis`, `pcntl`, `gd`, `exif` and the rest |
| Laravel | 13.x | |
| stancl/tenancy | 3.10.1+ | The first v3 release that supports Laravel 13 |
| MySQL | 8.4 | See [Database user](#database-user-privileges) |
| Redis | 7.4 | **Required**: per-tenant cache and session isolation relies on Redis key prefixes |
| Node.js | 22.12 | Only builds assets (Vite 8, Tailwind 4), in a one-off container |
| MinIO | Chainguard image | S3-compatible tenant buckets. Amazon S3 in production. See [docs/STORAGE.md](docs/STORAGE.md) |
| Mailpit | 1.29 | Catches every mail |

## Setup (local, Docker)

```bash
git clone https://github.com/touhidurabir/tenancey.git
cd tenancey
bin/setup
```

`bin/setup` creates `.env` from `.env.example`, starts the containers, installs the PHP packages,
fills the empty keys (`APP_KEY`, Reverb's id/key/secret), migrates and seeds the central database,
builds the assets, and starts Horizon, Reverb and the scheduler. It's safe to run again; it never
overwrites `.env` or a key that's set. What each step does: [docs/DOCKER.md](docs/DOCKER.md#first-run-of-the-project).

The central (`tenancey`) and test (`tenancey_testing`) databases are created by the MySQL
container on its first start (`docker/mysql/init/`). Change the seeded admin's password before
using this anywhere real.

**Ports already in use?** Change them in `.env` (the "Published ports" block) together with the URL
that uses each one (e.g. `APP_PORT=8001` and `APP_URL=http://localhost:8001`), then run
`bin/setup` again.

Important `.env` values (port mode, the default):

```dotenv
APP_URL=http://localhost:8000
CENTRAL_DOMAIN=localhost           # the landlord host; tenants live at {subdomain}.CENTRAL_DOMAIN
SESSION_DOMAIN=null                # keep null: host-only cookies keep tenants' sessions apart
SESSION_SECURE_COOKIE=false        # true in docker-edge mode (https)

DB_HOST=mysql                      # service names: the containers reach each other by name
DB_USERNAME=root                   # needs CREATE/DROP DATABASE and CREATE USER, see below
TENANT_DATABASE_PREFIX=tenancey_   # tenant databases are named tenancey_{uuid}
TENANT_DB_KEY=                     # optional separate key for tenant DB passwords; empty uses APP_KEY

FILESYSTEM_DISK=minio              # local, minio or s3: the disk NEW tenants get
MINIO_ENDPOINT=http://minio:9000   # PHP → MinIO, inside Docker
MINIO_PUBLIC_ENDPOINT=http://localhost:9000   # what download links are signed for

VITE_REVERB_HOST=localhost         # the browser's WebSocket address (baked into the JS)
VITE_REVERB_PORT=8080
```

Then:

1. Open http://localhost:8000 and sign in as `admin@tenancey.test` / `password`.
2. Create a tenant, for example subdomain `acme`. The tenant page shows each provisioning step live.
3. When it's Ready, open http://acme.localhost:8000 (Chrome or Firefox). Sign in with the tenant
   admin you entered, or use **Impersonate** from the central tenant page.

| Page | Port mode (default) | docker-edge mode |
|---|---|---|
| Horizon dashboard | http://localhost:8000/horizon | https://tenancey.test/horizon |
| Logs | http://localhost:8000/log-viewer | https://tenancey.test/log-viewer |
| Audit log | http://localhost:8000/audit | https://tenancey.test/audit |
| Mail inbox (Mailpit) | http://localhost:8025 | https://mail.tenancey.test |
| MinIO dashboard (`minioadmin` / `minioadmin`) | http://localhost:9001 | https://minio.tenancey.test |

Daily commands, the services and troubleshooting are in [docs/DOCKER.md](docs/DOCKER.md).

Upgrading an install made before buckets existed: run `php artisan migrate`, then
`php artisan tenants:migrate`, then `php artisan tenants:create-buckets` (safe to re-run), inside
the `app` container, and restart Horizon. Existing tenants are recorded on `local` and keep working.

## Database user privileges

The `.env` database user is the **master** user. It creates and drops tenant databases, and
optionally tenant users, so it needs:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'tenancey_master'@'localhost' WITH GRANT OPTION;
-- At minimum: CREATE, DROP, CREATE USER, and GRANT OPTION, plus normal data privileges.
```

Locally, `root` is fine.

- **Without** a dedicated user, tenant databases are accessed with the master credentials.
- **With** a dedicated user (a checkbox on the create form), that user is granted access only to
  its own tenant's database.

## Serving subdomains (production)

Laravel separates central and tenant traffic **by host name**, so every subdomain must reach the
same `public/index.php`:

1. **DNS:** an `A` record for `yourapp.com` and a wildcard record for `*.yourapp.com`, both
   pointing at your server.
2. **TLS:** a wildcard certificate for `*.yourapp.com`. With Let's Encrypt, that requires a
   DNS-01 challenge.
3. **Web server or proxy:** every host must reach `public/index.php`. nginx example:
   ```nginx
   server_name yourapp.com *.yourapp.com;
   root /var/www/tenancey/public;
   ```
   Behind a TLS-terminating proxy, list the proxy's address in `trustProxies` (`bootstrap/app.php`
   trusts any proxy, which is only safe when nothing else can reach the app).
4. **`.env`:** `APP_URL=https://yourapp.com`, `CENTRAL_DOMAIN=yourapp.com`.
5. **Workers:** run Horizon and Reverb as long-running processes (containers, or Supervisor with
   `deploy/supervisor/*.conf`), run the scheduler (`schedule:work`, or a cron with
   `schedule:run`), and restart Horizon after every deploy.

## Running the tests

The tests use real MySQL and Redis, and provision real tenant databases with test-only
prefixes. The settings are in `phpunit.xml`:

| Setting | Value |
|---|---|
| Test database | `tenancey_testing` |
| Tenant database prefix | `tenanceytest_` |
| Tenant DB user prefix | `tx_` |
| Redis databases | 13–15 |
| Tenant bucket prefix | `tenanceytest-` |

The MySQL container creates the test database. Run the suite inside the `app` container:

```bash
docker compose exec app php artisan test --compact
```

The test case refuses to run against any other database. It cleans up the test-prefixed tenant
resources before and after each test.

The `minio` group (`--group=minio`) talks to the `minio` container and is skipped when it isn't
running.

## How it fits together

| Read this | For |
|---|---|
| [docs/ROUTING.md](docs/ROUTING.md) | Central vs tenant routing: how `acme.tenancey.test` becomes a tenant, the middleware chain, the bootstrappers, and sessions |
| [docs/DOCKER.md](docs/DOCKER.md) | The development setup: how a request gets in, URLs, settings, services, daily commands, troubleshooting |
| [docs/STORAGE.md](docs/STORAGE.md) | File storage: `FILESYSTEM_DISK`, one bucket per tenant, signed downloads, MinIO, S3 in production |
| [docs/QUEUES.md](docs/QUEUES.md) | Driver vs queue connection vs queue, the Redis connections, Horizon supervisors and workers, and timeouts |
| [deploy/supervisor/README.md](deploy/supervisor/README.md) | Running Horizon and Reverb under Supervisor (not used locally any more) |
| [docs/superpowers/specs/](docs/superpowers/specs/) | The original design for tenant bootstrapping |
| [CLAUDE.md](CLAUDE.md) | Condensed project rules: tenant identity, provisioning job rules, the resource guard, and pitfalls |

Key places in the code:

| What | Where |
|---|---|
| Central routes, bound to `CENTRAL_DOMAIN` | `routes/web.php` |
| Tenant routes, for any subdomain | `routes/tenant.php` |
| Tenancy wiring (resolver, middleware, events) | `app/Providers/TenancyServiceProvider.php` |
| Provisioning and teardown | `app/Services/TenantProvisioner.php`, `app/Services/TenantTeardown.php`, `app/Jobs/` |
| Tenant migrations | `database/migrations/tenant/` |
| Adding tenant migrations later | `php artisan tenants:migrate` runs them on every tenant |

## Common problems

| Symptom | Cause |
|---|---|
| A tenant stays in "Provisioning" | Horizon isn't running: `docker compose ps` |
| `acme.localhost:8000` doesn't open | Safari can't resolve `*.localhost`. Use Chrome or Firefox |
| `bin/setup` or `docker compose up` fails with "port is already allocated" | Another program uses that port. Change it in `.env`'s "Published ports" block, with the URL that uses it |
| Live updates don't arrive after changing a `VITE_*` value or a port | The JS still has the old Reverb address baked in. Rebuild: `docker compose run --rm node` |
| `acme.tenancey.test` doesn't resolve (docker-edge mode) | No wildcard DNS for `*.tenancey.test` |
| Every tenant URL shows "No such workspace" | `CENTRAL_DOMAIN` doesn't match the host you are using |
| Signed into one tenant, you appear signed into another | `SESSION_DOMAIN` was set to `.yourapp.test`. Set it back to `null` |
| Pages try to load assets from `localhost:517x` | A stale `public/hot` file was left by a killed Vite HMR server. Delete it |
| Job changes have no effect | Workers keep old code in memory. Run `docker compose restart horizon` |

## Credits

- [Laravel](https://laravel.com)
- [stancl/tenancy](https://tenancyforlaravel.com)
- [spatie/laravel-permission](https://spatie.be/docs/laravel-permission)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer)

## License

MIT.
