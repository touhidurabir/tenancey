# Routing: central vs tenant

How one Laravel app serves both the landlord site (`tenancey.test`) and every tenant
(`acme.tenancey.test`), and where each piece is configured. For queues, see [QUEUES.md](QUEUES.md).

## 1. Getting a subdomain to Laravel at all

This happens before Laravel runs:

- **Locally:** Valet's dnsmasq sends every `*.test` name to 127.0.0.1. Valet serves the
  subdomains of a linked site (`tenancey`) from the same `public/index.php`. So `tenancey.test`,
  `acme.tenancey.test` and `beta.tenancey.test` all reach one app.
- **In production:** you need a wildcard DNS record (`*.yourdomain`), a wildcard TLS
  certificate, and a web server `server_name` that accepts the subdomains.

Laravel then tells central and tenant traffic apart **by host name only**.

## 2. Two route files

| File | Loaded by | Bound to a host? | Route names |
|---|---|---|---|
| `routes/web.php` | `bootstrap/app.php` → `withRouting(web: …)` | **Yes**: `Route::domain(config('tenancy.central_domains')[0])` | `central.*` |
| `routes/tenant.php` | `TenancyServiceProvider::mapRoutes()`, inside `$this->app->booted()` | **No**: it matches any host | `login`, `dashboard`, … |

**`config/tenancy.php` → `central_domains`** (from `CENTRAL_DOMAIN=tenancey.test`) is the single
list that says "this host is the landlord". These all read it:
- the `Route::domain()` in `web.php`
- stancl's middleware
- log-viewer (`route_domain`) and Horizon (`domain`)

## 3. How a route is picked

Laravel tries routes in **registration order** and takes the first match. `web.php` is
registered first and `tenant.php` later, because it loads in `booted`.

| Request | What matches | Result |
|---|---|---|
| `tenancey.test/login` | `central.login`: the host and path match | Central login, 200 |
| `acme.tenancey.test/login` | `central.login` fails its host check, so the tenant `login` matches | Tenant login, 200 |
| `acme.tenancey.test/tenants` | Only central routes have `/tenants`, and they are host-bound | 404 |
| `tenancey.test/dashboard` | No central route; the tenant `dashboard` matches because it accepts any host… | …tenant middleware refuses it: 404 |
| `nosuch.tenancey.test/login` | Tenant `login`, but no tenant has that subdomain | "No such workspace", 404 |
| `deleted.tenancey.test/…` | The resolver skips soft-deleted tenants | "No such workspace", 404 |

So the two worlds are kept apart in different ways:
- **Central routes are kept off tenant hosts** by `Route::domain()`.
- **Tenant routes are kept off the central host** by middleware (section 4).

## 4. The tenant middleware chain

```php
// routes/tenant.php
Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,      // which tenant is this?
    PreventAccessFromCentralDomains::class,   // never on tenancey.test
    EnsureTenantIsActive::class,              // enabled AND Ready?
    HandleImpersonation::class,               // admin "signed in as" session
])
```

1. **`InitializeTenancyBySubdomain`** (stancl). `makeSubdomain()` splits the host on `.`.
   - It throws `NotASubdomainException` if the host is a central domain, `localhost`, an IP, or
     doesn't end in a central domain.
   - Otherwise it takes the first part (`acme`) and passes it to the resolver.
2. **The resolver.** Stancl normally looks hosts up in its `domains` table, but this project has
   none. `TenancyServiceProvider::register()` binds **`App\Tenancy\SubdomainTenantResolver`** in
   place of `DomainTenantResolver`. It runs `Tenant::where('subdomain', 'acme')->first()`, so the
   SoftDeletes scope excludes deleted tenants.
3. **Failure handling** (`TenancyServiceProvider::handleUnknownSubdomains()` sets `$onFail`):
   - **Unknown subdomain:** the `tenant.unknown` view, 404.
   - **Any other failure, e.g. the central host hitting a tenant route:** `abort(404)`.
4. **Tenancy initialized.** Stancl fires `TenancyInitialized`, and the
   `config/tenancy.php` → `bootstrappers` switch the app over:

   | Bootstrapper | What switches |
   |---|---|
   | `DatabaseTenancyBootstrapper` | The default DB connection becomes `tenant`, i.e. `tenancey_{uuid}` with that tenant's credentials |
   | `TenantRedisBootstrapper` | The `default` and `cache` Redis connections (sessions and cache) get the tenant's `cache_prefix` |
   | `FilesystemTenancyBootstrapper` | Storage paths move to the tenant's folder |
   | `QueueTenancyBootstrapper` | Jobs dispatched on tenant-aware connections carry the tenant id |
   | `SpatiePermissionsBootstrapper` | The permission cache key moves to the tenant's own |

5. **`PreventAccessFromCentralDomains`** returns 404 if the host is in `central_domains`. It
   repeats the check from step 1, as a second safeguard.
6. **`EnsureTenantIsActive`** checks the tenant on every request, so disabling a tenant locks
   out its signed-in users on their next click:

   | Tenant | Response |
   |---|---|
   | Enabled and Ready | Request continues |
   | Enabled, still provisioning | `tenant.provisioning` page, 503 |
   | Disabled or failed | `tenant.unavailable` page, 403 |

7. **`HandleImpersonation`**: the banner, the 60-minute limit, and the end of impersonation
   (see CLAUDE.md → Impersonation).

## 5. Sessions: why middleware priority matters

`TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` moves stancl's middleware to the
front of Laravel's priority list, **ahead of `StartSession`** (part of the `web` group).

- Sessions live in Redis (`SESSION_DRIVER=redis`). Because the tenant is known before the
  session is read, the session comes from **that tenant's** Redis prefix.
- Without the priority change, the session would load from the central keys before tenancy
  started.

`SESSION_DOMAIN=null` makes every session cookie **host-only**. So `tenancey.test`,
`acme.tenancey.test` and `beta.tenancey.test` each have their own cookie, and a central login
never carries over into a tenant. Setting `SESSION_DOMAIN=.tenancey.test` would break this
separation.

## 6. Consequences to remember

- **Both worlds have a "login" route.** The central routes are named `central.*`, and the
  tenant routes keep plain names. Shared code chooses between them with
  `tenancy()->initialized`, as `bootstrap/app.php` does:
  ```php
  $middleware->redirectGuestsTo(fn () => tenancy()->initialized ? route('login') : route('central.login'));
  ```
- **Tenant `route()` URLs have no host.** `route('dashboard')` uses the current request's host.
  That is right inside a tenant request, but wrong in a queued job, a mail or a central page:
  there you get `APP_URL`. Use **`$tenant->url()`** there.
- **Guards follow the world:**
  - Central routes use guard `central` (`CentralUser`, pinned to the landlord DB).
  - Tenant routes use `web` (`User`, the tenant DB).
  - Never use `web` on a central route: with no tenancy, `User` would read the landlord `users`
    table.
- **Vendor UIs are central only.** `/horizon` and `/log-viewer` are bound to `CENTRAL_DOMAIN` in
  their own config files. On a tenant subdomain they return 404.
- **Adding a page:**
  - Landlord page: put it inside the `Route::domain(...)` group in `web.php`, named `central.…`.
  - Tenant page: put it inside the middleware group in `tenant.php`. A tenant route outside that
    group would run on any host **without tenancy**, against the landlord DB.
- **Adding a central domain** (e.g. `admin.example.com`): add it to `central_domains`. Note that
  `web.php` only binds to the **first** entry (`[0]`).

## 7. Where everything lives

| What | Where |
|---|---|
| Central domain | `.env` `CENTRAL_DOMAIN` → `config/tenancy.php` `central_domains` |
| Central routes | `routes/web.php` (loaded in `bootstrap/app.php`) |
| Tenant routes | `routes/tenant.php` (loaded in `TenancyServiceProvider::mapRoutes()`) |
| Subdomain → tenant lookup | `App\Tenancy\SubdomainTenantResolver` (bound in `TenancyServiceProvider::register()`) |
| Unknown subdomain page | `TenancyServiceProvider::handleUnknownSubdomains()` → `resources/views/tenant/unknown.blade.php` |
| What changes once a tenant is found | `config/tenancy.php` `bootstrappers` |
| Tenancy before sessions | `TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()` |
| Enabled/Ready gate | `App\Http\Middleware\EnsureTenantIsActive` |
| Guest and user redirects | `bootstrap/app.php` `withMiddleware()` |
