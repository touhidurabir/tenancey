# Tenant bootstrapping — design

Status: approved in chat on 2026-10-02. Implementation follows this document.

## Revision 2 (2026-10-02, same day): supersedes the conflicting parts below

- `tenants.id` is an int auto-increment; `uuid` is stancl's tenant key (lookups, queue payloads,
  `tenants:*`, admin URLs). DB `tenancey_{uuid}`, storage `storage/tenant_{uuid}`, Redis prefix
  `tenancey_{uuid}:`.
- No `domains` table: unique `tenants.subdomain` (3–63 chars) and `App\Tenancy\SubdomainTenantResolver`.
- `status` is split: `enabled` (bool, default true) and `state` (`TenantState` int: 1 Provisioning,
  2 Ready, 3 Failed, 4 Deleting, 5 Deleted, with `describe()`). Open when enabled AND Ready.
- Soft deletes. Delete = teardown chain (drop DB, optional MySQL user, storage, Redis), then
  state Deleted plus soft delete; the record stays and the subdomain stays reserved.
- `db_username`/`db_password` nullable; null = master `.env` credentials (default, no UI to set them).
  Stancl manager is the plain `MySQLDatabaseManager` (see CLAUDE.md for the anonymous-user incident).
- spatie/laravel-permission in tenant DBs: roles `admin` and `member`; first user gets `admin`.
- Horizon runs under supervisor (`deploy/supervisor/`).

## Revision 3 (2026-10-03): supersedes the conflicting parts above

- **Dedicated database user.** An optional checkbox on the create form; empty username or
  password fields are generated (16-character username, 12–24-character password).
  - Typed usernames are validated: pattern, reserved names, unique across tenants, and not
    already present on the server.
  - `db_password` is encrypted with `TENANT_DB_KEY`, falling back to `APP_KEY`
    (`App\Casts\TenantSecret`).
  - `db_master_access` is recorded only; the master user's privileges are never changed.
  - `db_user_created_at` is the ownership marker: users we did not create are never dropped or
    recreated.
  - Users are managed through the `App\Tenancy\Database\DatabaseUserManager` interface
    (MySQL implementation; Postgres-ready), which quotes every value.
- **Must-change-password.** A checkbox for the first user, ticked by default.
- **Logging.** A daily `tenancy` log channel with Laravel Context (tenant, run, step). It is
  browsable at `/log-viewer` (opcodesio/log-viewer, central admins only, no deleting).
- **Audit log.** An append-only central `audit_logs` table, mirrored to the `tenancy` channel,
  with a `/audit` page.
- **Impersonation.** A central admin enters an enabled, Ready tenant as its first admin.
  - Hashed one-time token valid 60s.
  - Banner; 60-minute session; no forced password change; password page blocked.
  - Every step audited, refusals and rejections included.

## Goal

A central (landlord) admin creates tenants from a Blade UI. Each tenant gets its own MySQL database,
its own MySQL user, its own storage folder and its own cache prefix, provisioned by a queued chain of
single-purpose jobs managed by Horizon. When provisioning finishes, the tenant's first user receives
their credentials by email and can sign in at `http://{subdomain}.tenancey.test/login`.

Package: `stancl/tenancy` v3 (multi-database).

Out of scope: React, billing/plans, impersonation, tenant self-signup, forgot-password, email
verification, pre-delete backups, resource syncing.

## 1. Data model

### Landlord DB `tenancey`

- `users` — central admins, model `App\Models\CentralUser` (pinned to the central connection,
  guard `central`, provider `central_users`). Seeded: `admin@tenancey.test`.
- `tenants`

| column | notes |
|---|---|
| `id` | string PK = subdomain, immutable |
| `name` | editable |
| `status` | `provisioning` / `active` / `disabled` / `failed` / `deleting` |
| `db_name` | `tenancey_tenant_{id}` (project prefix — the MySQL server may be shared with other projects) |
| `db_username` | `tt_{id}`, a per-tenant MySQL user (MySQL user names ≤ 32 chars) |
| `db_password` | random, `encrypted` cast |
| `storage_path` | `storage/tenant{id}` (stancl FilesystemTenancyBootstrapper) |
| `cache_prefix` | Redis prefix used by stancl's Redis/Cache bootstrappers |
| `pending_admin` | encrypted JSON {name, email, phone, password}; nulled after the credentials email |
| `current_step`, `last_error`, `provisioned_at` | progress + failure reporting (provisioning and teardown) |
| `data` | stancl JSON column |

The columns are the source of truth: the Tenant model maps stancl's internal `db_name`,
`db_username`, `db_password` keys onto them.

- `domains` — stancl; stores the subdomain (`tenant1`).

### Tenant DB (`database/migrations/tenant`)

- `users` (+ `phone`, `must_change_password`), `password_reset_tokens`.
- `TenantDatabaseSeeder` — empty hook.

### Subdomain rules

`^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`, 3–29 chars, unique in `tenants`, not reserved
(`www admin api mail app central tenancey static assets horizon`).

## 2. Provisioning pipeline

A `TenantProvisioner` service saves the tenant + domain with `status = provisioning` in a
transaction (stancl's own event pipelines are switched off, so events can stay on), then dispatches a `Bus::chain` on connection/queue `provisioning`:

1. `CreateTenantDatabase`
2. `CreateTenantDatabaseUser` — `CREATE USER IF NOT EXISTS`, `GRANT ALL` on its DB only
3. `MigrateTenantDatabase` — connects as the tenant's own MySQL user
4. `SeedTenantDatabase`
5. `PrepareTenantStorage`
6. `CreateFirstTenantUser` — `firstOrCreate` by email, `must_change_password = true`
7. `ActivateTenant` — `status = active`, `provisioned_at`
8. `SendTenantCredentials` — sends login URL + email + password, then nulls `pending_admin`

Every job: takes only the tenant id and reloads the row; ends tenancy in `finally`; records `current_step`; is idempotent;
`tries = 3`, backoff `[10, 60]`. Stancl's own `TenantCreated`/`TenantDeleted` job pipelines are
switched off.

Failure: before activation → `status = failed` + `last_error`, Retry re-dispatches the whole
chain (finished steps skip). After activation (mail) → tenant stays `active`, `last_error`
recorded, "Resend credentials" re-dispatches the chain.

## 2b. Queues and Horizon

- Redis connection `queue` — not in stancl's prefixed connections.
- Queue connections: `redis` (default, tenant-aware, retry_after 150), `central` (`central => true`,
  retry_after 360), `provisioning` (`central => true`, retry_after 900).
- Horizon supervisors (local/production): default 1/3 processes timeout 120; central 1/2 timeout 300;
  provisioning 1/2 timeout 600. Every timeout < its connection's `retry_after`.
- Dashboard at `tenancey.test/horizon`, gate checks `user('central')`.
- `composer dev` runs Horizon instead of `queue:listen`.

## 3. Routing and auth

- `central_domains = [CENTRAL_DOMAIN]`; tenants identified by subdomain.
- `routes/web.php` on the central domain: `/login` (guard `central`), tenants CRUD, progress,
  retry, delete, Horizon.
- `routes/tenant.php`: `web` + subdomain identification + `PreventAccessFromCentralDomains` +
  `EnsureTenantIsActive`: `/login` (guard `web`), `/dashboard`, `/password/change`.
- Unknown subdomain → 404 page. `provisioning` → 503. `disabled`/`failed`/`deleting` → 403.
- `EnsurePasswordChanged` forces `/password/change` while `must_change_password`.
- Host-only session cookies; sessions and cache on Redis (prefixed per tenant); tenancy middleware
  runs before the session starts; login rate limiter keyed by tenant + email.
- Links in queued mail are built with `Tenant::url()`, never `url()`.

## 4. Lifecycle, errors, testing

- Edit: name only. Disable/enable: `active` ↔ `disabled`, effective next request.
- Delete: type the subdomain to confirm → `status = deleting` → teardown chain on `provisioning`:
  `DropTenantDatabase`, `DropTenantDatabaseUser`, `DeleteTenantStorage`, `FlushTenantCache`,
  `DeleteTenantRecord`. Drop jobs refuse names without the `tenancey_tenant_` / `tt_` prefix.
  Teardown failure → stays `deleting` with `last_error`, "Retry delete".
- Cache isolation relies on the per-tenant Redis prefix (stancl's CacheTenancyBootstrapper is not
  used), so the cache store must be Redis.
- Tests: DB `tenancey_testing`, tenant prefix `tenancey_testtenant_`, sync queue, faked mail;
  base test case refuses to run unless the DB name ends in `_testing` and drops what it created.
