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
  3. Prepare storage.
  4. Create the first admin user.
  5. Mark the tenant Ready.
  6. Email the credentials.

  Every step is idempotent, and a failed run can be retried from the admin UI.
- **Queued teardown** drops the database, the user, storage and Redis keys, then soft-deletes the
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
- **Horizon** runs the queues, with separate tenant-aware, central and provisioning connections.
- **Feature tests** run against real MySQL and provision real tenant databases.

## Requirements

| Requirement | Version | Notes |
|---|---|---|
| PHP | **8.3+** (developed on 8.4) | Extensions: `pdo_mysql`, `redis` (phpredis), `pcntl` and `posix` (for Horizon), `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `intl` (recommended) |
| Composer | 2.x | |
| Laravel | 13.x | Installed by Composer |
| stancl/tenancy | 3.10.1+ | The first v3 release that supports Laravel 13 |
| MySQL | 8.0+ (developed on 9.2) | MariaDB should also work. See [Database user](#database-user-privileges) |
| Redis | 6+ (developed on 7.2) | Holds queues, cache and sessions, and is **required**: per-tenant cache and session isolation relies on Redis key prefixes |
| Node.js | **22.12.0+** | Pinned in `.nvmrc`. Used only to build assets with Vite 8 and Tailwind 4 |
| npm | 10+ | Ships with Node 22 |
| A web server that serves wildcard subdomains | | Locally: [Laravel Valet](https://laravel.com/docs/valet) or [Herd](https://herd.laravel.com) on macOS. Elsewhere: nginx or Apache with wildcard DNS |
| A process supervisor | | [Supervisor](http://supervisord.org/) runs Horizon. Without Horizon, nothing is ever provisioned |
| SMTP catcher (dev) | | Mailpit, HELO, Mailtrap, or `MAIL_MAILER=log` |

`php artisan serve` is **not enough**: it cannot serve `*.yourapp.test` subdomains, so tenants
would be unreachable.

## Setup (local)

These steps assume the central domain `tenancey.test`. Use any domain you like, but keep
`APP_URL` and `CENTRAL_DOMAIN` in step 3 matching it.

### 1. Clone and install

```bash
git clone https://github.com/touhidurabir/tenancey.git
cd tenancey
nvm use                # or install Node 22.12+ another way
composer install
npm install
```

### 2. Serve the app and its subdomains

With **Valet**:

```bash
valet link tenancey    # serves tenancey.test AND *.tenancey.test
```

Valet's DNS resolves every `*.test` name. **Herd** works the same way: link the directory.
On Linux or Windows, see [Serving subdomains](#serving-subdomains-production-or-without-valet).

### 3. Configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Then check these values:

```dotenv
APP_URL=http://tenancey.test
CENTRAL_DOMAIN=tenancey.test       # the landlord host; tenants live at {subdomain}.CENTRAL_DOMAIN

DB_DATABASE=tenancey               # the central (landlord) database
DB_USERNAME=root                   # needs CREATE/DROP DATABASE and CREATE USER, see below
DB_PASSWORD=

TENANT_DATABASE_PREFIX=tenancey_   # tenant databases are named tenancey_{uuid}
TENANT_DB_USER_PREFIX=             # optional prefix for generated tenant DB usernames
TENANT_DB_KEY=                     # optional separate key for tenant DB passwords; empty uses APP_KEY

SESSION_DRIVER=redis
SESSION_DOMAIN=null                # keep null: host-only cookies keep tenants' sessions apart
CACHE_STORE=redis
QUEUE_CONNECTION=redis

MAIL_HOST=127.0.0.1                # point at your mail catcher
MAIL_PORT=2525
```

If you share a MySQL server with other projects, choose a `TENANT_DATABASE_PREFIX` nobody else
uses.

### 4. Create the central database, migrate and seed

```bash
mysql -uroot -e "CREATE DATABASE tenancey CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
php artisan db:seed      # central admin: admin@tenancey.test / password
```

Change the seeded admin's password before using this anywhere real.

### 5. Build assets

```bash
npm run build   # production build, or `npm run dev` for one unminified development build
```

### 6. Run Horizon

Provisioning and teardown are queued, so **Horizon must be running**. Without it, new tenants
stay in "Provisioning" forever.

- Quick start: run `php artisan horizon` in a terminal.
- Alternatively, `composer dev` runs the dev server, Horizon, logs and Vite HMR together.
- To keep Horizon running permanently, use Supervisor: see
  [deploy/supervisor/README.md](deploy/supervisor/README.md). The file in
  `deploy/supervisor/local/` contains absolute paths, so edit them for your machine.

Run only one Horizon at a time.

### 7. Try it

1. Open http://tenancey.test and sign in as `admin@tenancey.test` / `password`.
2. Create a tenant, for example subdomain `acme`. The tenant page shows each provisioning step as
   it runs.
3. When it's Ready, open http://acme.tenancey.test. Sign in with the tenant admin you entered, or
   use **Impersonate** from the central tenant page.

Other central pages:

| Page | Path |
|---|---|
| Horizon dashboard | http://tenancey.test/horizon |
| Logs | http://tenancey.test/log-viewer |
| Audit log | http://tenancey.test/audit |

### 8. Scheduler (optional locally, needed in production)

Expired impersonation tokens are pruned by the scheduled `model:prune`:

```bash
php artisan schedule:work    # local
# production cron: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

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

## Serving subdomains (production, or without Valet)

Laravel separates central and tenant traffic **by host name**, so every subdomain must reach the
same `public/index.php`:

1. **DNS:** an `A` record for `yourapp.com` and a wildcard record for `*.yourapp.com`, both
   pointing at your server. Locally on Linux, use `dnsmasq`
   (`address=/yourapp.test/127.0.0.1`), or add `/etc/hosts` lines for each tenant.
2. **TLS:** a wildcard certificate for `*.yourapp.com`. With Let's Encrypt, that requires a
   DNS-01 challenge.
3. **Web server:** nginx example:
   ```nginx
   server_name yourapp.com *.yourapp.com;
   root /var/www/tenancey/public;
   ```
4. **`.env`:** `APP_URL=https://yourapp.com`, `CENTRAL_DOMAIN=yourapp.com`.
5. **Workers:** run Horizon under Supervisor
   (`deploy/supervisor/tenancey-horizon.conf`), add the scheduler cron, and run
   `php artisan horizon:terminate` after every deploy.

## Running the tests

The tests use real MySQL and Redis, and provision real tenant databases with test-only
prefixes. The settings are in `phpunit.xml`:

| Setting | Value |
|---|---|
| Test database | `tenancey_testing` |
| Tenant database prefix | `tenanceytest_` |
| Tenant DB user prefix | `tx_` |
| Redis databases | 13–15 |

Create the test database once, then run the suite:

```bash
mysql -uroot -e "CREATE DATABASE tenancey_testing"
php artisan test
```

The test case refuses to run against any other database. It cleans up the test-prefixed tenant
resources before and after each test.

## How it fits together

| Read this | For |
|---|---|
| [docs/ROUTING.md](docs/ROUTING.md) | Central vs tenant routing: how `acme.tenancey.test` becomes a tenant, the middleware chain, the bootstrappers, and sessions |
| [docs/QUEUES.md](docs/QUEUES.md) | Driver vs queue connection vs queue, the Redis connections, Horizon supervisors and workers, and timeouts |
| [deploy/supervisor/README.md](deploy/supervisor/README.md) | Running Horizon under Supervisor |
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
| A tenant stays in "Provisioning" | Horizon isn't running |
| `acme.tenancey.test` doesn't resolve | The site isn't linked in Valet or Herd, or there's no wildcard DNS |
| Every tenant URL shows "No such workspace" | `CENTRAL_DOMAIN` doesn't match the host you are using |
| Signed into one tenant, you appear signed into another | `SESSION_DOMAIN` was set to `.yourapp.test`. Set it back to `null` |
| Pages try to load assets from `localhost:517x` | A stale `public/hot` file was left by a killed Vite HMR server. Delete it |
| Job changes have no effect | Workers keep old code in memory. Run `php artisan horizon:terminate` |

## Credits

- [Laravel](https://laravel.com)
- [stancl/tenancy](https://tenancyforlaravel.com)
- [spatie/laravel-permission](https://spatie.be/docs/laravel-permission)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer)

## License

MIT.
