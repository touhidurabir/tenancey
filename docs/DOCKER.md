# Docker (local development)

Tenancey runs only in Docker, in one of two modes:
- **Port mode (default):** `compose.yaml` alone. Each service is published on a `localhost` port.
  Plain http, no proxy, no DNS or certificates. A fresh clone runs with `bin/setup`.
- **docker-edge mode:** `compose.yaml` plus `compose.edge.yaml`. HTTPS on `tenancey.test` through
  a shared Traefik proxy, **docker-edge**, which lives in its own folder (`~/Sites/docker-edge`)
  and can serve other Docker projects too. The author's Mac runs this mode.

| What | Port mode (default) | docker-edge mode |
|---|---|---|
| Central app (Horizon `/horizon`, logs `/log-viewer`, audit `/audit`) | `http://localhost:8000` | `https://tenancey.test` |
| A tenant | `http://{subdomain}.localhost:8000` | `https://{subdomain}.tenancey.test` |
| WebSockets (Reverb) | `ws://localhost:8080` | `wss://ws.tenancey.test` |
| File links (MinIO API) | `http://localhost:9000` | `https://s3.tenancey.test` |
| MinIO dashboard (`minioadmin` / `minioadmin`) | `http://localhost:9001` | `https://minio.tenancey.test` |
| Mail inbox (Mailpit) | `http://localhost:8025` | `https://mail.tenancey.test` |
| MySQL from the host (`root` / `docker`) | `127.0.0.1:3307` | `127.0.0.1:3307` |
| Traefik dashboard | – | `http://127.0.0.2:18090` |

Tenant pages on `*.localhost` need Chrome or Firefox (they resolve those names themselves). Safari
uses the system resolver, which doesn't. In docker-edge mode, `http://` addresses redirect to
`https://`. `s3`, `minio`, `ws` and `mail` are reserved subdomains
(`StoreTenantRequest::RESERVED_SUBDOMAINS`), so no tenant can take them.

Designs: `docs/superpowers/specs/2026-10-07-docker-local-dev-design.md` (the containers),
`docs/superpowers/specs/2026-10-13-docker-only-https-design.md` (HTTPS, docker-edge) and
`docs/superpowers/specs/2026-10-14-standalone-port-mode-design.md` (the two modes, `bin/setup`).

## Two modes

**Port mode** is what `compose.yaml` gives on its own. These ports are published, each bound to
`127.0.0.1` (this machine only) and read from `.env`:

| Service | `.env` variable | Default | Container port |
|---|---|---|---|
| nginx (central and tenants) | `APP_PORT` | 8000 | 80 |
| reverb | `FORWARD_REVERB_PORT` | 8080 | 8080 |
| mailpit (inbox) | `FORWARD_MAILPIT_DASHBOARD_PORT` | 8025 | 8025 |
| minio (S3 API) | `FORWARD_MINIO_PORT` | 9000 | 9000 |
| minio (dashboard) | `FORWARD_MINIO_CONSOLE_PORT` | 9001 | 9001 |
| mysql | `FORWARD_DB_PORT` | 3307 | 3306 |

A port that's taken on your machine: change the variable **and** the URL that contains it
(`APP_URL`, `VITE_REVERB_PORT`, `MINIO_PUBLIC_ENDPOINT`, `MINIO_CONSOLE_URL`), then `bin/setup`
(or `docker compose up -d` and `docker compose run --rm node`).

**docker-edge mode** lays `compose.edge.yaml` over `compose.yaml`. The override:
- puts nginx, reverb, mailpit and minio on the external `edge` network as well;
- adds the Traefik labels (host names hard-coded to `tenancey.test`);
- removes their published ports with `ports: !reset []` (MySQL keeps `127.0.0.1:3307`);
- sets MinIO's dashboard address to `https://minio.tenancey.test`.

It's turned on by one line in `.env`, which Compose reads itself (Laravel ignores it):
```
COMPOSE_FILE=compose.yaml:compose.edge.yaml
```
Every `docker compose` command then uses both files, with no `-f` flags. `docker compose config`
prints the merged result.

**Switching modes:** use the values in `.env.example`'s commented docker-edge block (or the
port-mode values above it), including `COMPOSE_FILE`, then:
```bash
docker compose up -d            # recreates what changed
docker compose run --rm node    # VITE_* (the browser's Reverb address) are baked into the JS
```
docker-edge mode needs docker-edge running first (see below).

## How a request gets in

**Port mode:**
```
browser ── http://acme.localhost:8000   (the browser resolves *.localhost to this machine)
   ▼
127.0.0.1:8000 (published port) ──► nginx:80 ──► app:9000 (PHP-FPM) ──► Laravel
```
No proxy, so no `X-Forwarded-*` headers: Laravel builds `http://` URLs with the port from the
request.

**docker-edge mode:**
```
browser ── https://acme.tenancey.test
   │ DNS: dnsmasq answers tenancey.test and *.tenancey.test with 127.0.0.2
   ▼
127.0.0.2:443 ── socat (root launch daemon) ──► 127.0.0.2:18443
   ▼
traefik (docker-edge): HTTPS ends here, mkcert certificate; picks a router by host name
   ├─ tenancey.test, *.tenancey.test ──► nginx:80 ──► app:9000 (PHP-FPM) ──► Laravel
   ├─ ws.tenancey.test               ──► reverb:8080
   ├─ s3.tenancey.test               ──► minio:9000 (S3 API)
   ├─ minio.tenancey.test            ──► minio:9001 (dashboard)
   └─ mail.tenancey.test             ──► mailpit:8025
```
- **`127.0.0.2`** is a second loopback address. Valet (still used by other projects) keeps
  `127.0.0.1:80/443`, so Docker can have clean URLs without a port.
- **socat** exists because Docker Desktop (4.89+) can't publish ports below 1024 on one specific
  address. A root launch daemon holds `127.0.0.2:80/443` and passes the bytes to Traefik's
  published `18080/18443`.
- **Traefik** reads its routes from labels on tenancey's containers (`compose.edge.yaml`). The tenant
  wildcard router has priority 1 and the named side services 100. Otherwise the wildcard, whose
  rule is longest, would win `ws.`, `s3.` and the others.
- **Laravel trusts the proxy** (`trustProxies(at: '*')` in `bootstrap/app.php`). Traefik sends
  plain http to nginx with `X-Forwarded-Proto: https`, and Laravel builds `https://` URLs from it.
  Traefik deletes look-alike headers such as `X_Forwarded_Proto` (`aliasHeadersStrategy=delete`).
- Only `nginx`, `reverb`, `minio` and `mailpit` join the shared `edge` network. PHP, MySQL and
  Redis stay on the project's own network.

## One-time Mac setup

**docker-edge mode only.** Port mode needs none of this.

All of it is described, with undo steps, in `~/Sites/docker-edge/README.md`. docker-edge is a
separate folder (its own repo), not part of this one. Without it, any Traefik v3 that creates an
external network named `edge`, serves a trusted certificate for `tenancey.test` + `*.tenancey.test`,
and reads Docker labels will do: `compose.edge.yaml`'s labels are written for that.
1. The `127.0.0.2` address and the two socat forwarders: launch daemons in
   `/Library/LaunchDaemons` (sources in `~/Sites/docker-edge/mac/`). Needs `brew install socat`.
2. `brew install mkcert` and `mkcert -install` (the local certificate authority, trusted once).
   Then the certificate, listed in docker-edge's `dynamic/tls.yaml`:
   `mkcert -cert-file certs/tenancey.test.pem -key-file certs/tenancey.test-key.pem tenancey.test "*.tenancey.test"`
   (run in `~/Sites/docker-edge`).
3. docker-edge: `cd ~/Sites/docker-edge && docker compose up -d`. **Start it before tenancey**:
   `compose.edge.yaml` joins its `edge` network (`external: true`).
4. DNS: `~/.config/valet/dnsmasq.d/tenancey.conf` with `address=/tenancey.test/127.0.0.2`, then
   `sudo brew services restart dnsmasq`. It is more specific than Valet's `.test` rule, so it wins.

## First run of the project

```bash
bin/setup
```
It needs only Docker and bash; every PHP and Node step runs in a container. In order:
1. Checks that `docker compose` works.
2. Copies `.env.example` to `.env`, only if there's no `.env`.
3. Starts MySQL, Redis, MinIO, Mailpit, `app` and nginx, and waits for MySQL to be healthy
   (`up -d --wait`). Horizon, Reverb and the scheduler wait: they exit without `vendor/`.
4. `composer install` in `app`.
5. Fills **empty** keys only: `APP_KEY` (`key:generate`) and `REVERB_APP_ID` / `_KEY` / `_SECRET`
   (random values). A key that's set is never changed.
6. `migrate --force --seed` (the seeder uses `firstOrCreate`).
7. Builds the assets (`docker compose run --rm node`), baking in the `VITE_*` values.
8. Starts everything (`up -d`) and restarts Horizon and Reverb, so a re-run picks up new code.
9. Prints the addresses and the admin login (`admin@tenancey.test` / `password`).

It's safe to run again: after a failure, fix the cause and re-run. It honours `COMPOSE_FILE` and
`COMPOSE_PROJECT_NAME` from `.env`, so it works the same in docker-edge mode (start docker-edge
first). `TENANT_DB_KEY` can stay empty (it falls back to `APP_KEY`).

## Settings: `.env` only

`.env` holds Docker's values and is the only source of settings. It has two readers:
- **Laravel**, inside the containers (they bind-mount the project, so they read it directly);
- **Docker Compose**, on the host: the published ports (`${APP_PORT:-8000}`…), `MINIO_CONSOLE_URL`,
  and its own `COMPOSE_FILE` / `COMPOSE_PROJECT_NAME`.

`compose.yaml` sets no Laravel settings, only what configures MySQL and MinIO themselves.

| Setting | Port mode | docker-edge mode | Who uses it |
|---|---|---|---|
| `APP_URL` / `CENTRAL_DOMAIN` | `http://localhost:8000` / `localhost` | `https://tenancey.test` / `tenancey.test` | URLs. `Tenant::url()` takes the scheme and port from `APP_URL` |
| `SESSION_SECURE_COOKIE` | `false` | `true` | cookies only over https |
| `DB_HOST` / `REDIS_HOST` / `MAIL_HOST` | `mysql` / `redis` / `mailpit` | same | PHP, inside Docker |
| `REVERB_HOST` / `REVERB_PORT` | `reverb` / `8080` | same | PHP sending events, inside Docker |
| `VITE_REVERB_HOST` / `_PORT` / `_SCHEME` | `localhost` / `8080` / `http` | `ws.tenancey.test` / `443` / `https` | the browser. **Baked into the JS at build time** |
| `MINIO_ENDPOINT` | `http://minio:9000` | same | PHP, inside Docker |
| `MINIO_PUBLIC_ENDPOINT` | `http://localhost:9000` | `https://s3.tenancey.test` | what download links are signed for |
| `COMPOSE_FILE` | unset | `compose.yaml:compose.edge.yaml` | Docker Compose |

**Don't run `config:cache`, `route:cache` or `optimize` in development:** a cached config hides
later `.env` changes. If it happens, `php artisan optimize:clear`.

## The PHP image (`docker/php/`)

One image, `tenancey-php:dev`, runs all four PHP services (`app`, `horizon`, `reverb`,
`scheduler`). It is `php:8.4-fpm` plus:
- extensions `pdo_mysql`, `pcntl` (bundled) and `redis` 6.3.0 (PECL); `posix` is built in;
- `gd` (JPEG, PNG, WebP, FreeType) and `exif`, for medialibrary's thumbnails;
- `git`, `unzip` and Composer 2;
- `docker/php/php.ini`, loaded last as `zz-tenancey.ini` (512M memory, errors shown).

It holds no code: the project is bind-mounted at `/var/www/html`, so code changes apply on the
next request. The build context is `docker/php/`, so the project folder is never sent to the
builder. Rebuild after changing anything in `docker/php/`: `docker compose up -d --build`.

## Services

| Service | Image | Role | Networks (docker-edge mode adds `edge`) |
|---|---|---|---|
| `app` | `tenancey-php:dev` | PHP-FPM (`app:9000`) | default |
| `horizon` | `tenancey-php:dev` | `php artisan horizon` (queues) | default |
| `reverb` | `tenancey-php:dev` | `php artisan reverb:start` (`reverb:8080`) | default (+ edge) |
| `scheduler` | `tenancey-php:dev` | `php artisan schedule:work` | default |
| `nginx` | `nginx:1.30-alpine` | static files, FastCGI to `app` | default (+ edge) |
| `mysql` | `mysql:8.4` | databases. Published on `127.0.0.1:3307` in both modes | default |
| `redis` | `redis:7.4-alpine` | queues, cache, sessions | default |
| `mailpit` | `axllent/mailpit:v1.29` | catches all mail (`mailpit:1025`) | default (+ edge) |
| `minio` | `cgr.dev/chainguard/minio@sha256:…` | tenant buckets (`minio:9000`, dashboard `:9001`) | default (+ edge) |
| `node` | `node:22.12-bookworm-slim` | one-off asset builds (profile `tools`) | default |

### MySQL and Redis

- On its first start (an empty volume), MySQL runs `docker/mysql/init/*.sql`, which creates
  `tenancey` and `tenancey_testing`.
- Containers reach each other by **service name**. Inside a container, `127.0.0.1` is the
  container itself.
- `docker compose down` keeps the data. `docker compose down -v` deletes MySQL, Redis and MinIO
  data (every tenant and its bucket). Delete tenants in the UI first.

```bash
docker compose exec mysql mysql -uroot -pdocker -e 'SHOW DATABASES'
docker compose exec redis redis-cli ping
```

### nginx

- nginx (`docker/nginx/default.conf`) answers every host name (`server_name _`). Laravel tells
  central and tenants apart by the Host header, which Traefik passes on unchanged. It mounts only
  `public/`, read-only.
- nginx finds `app` through Docker's DNS server (`resolver 127.0.0.11 valid=10s`, and
  `fastcgi_pass` through a variable), per request rather than once at start. A recreated `app`
  gets a new IP. Without this, nginx kept the old one and answered 502 until restarted.

### Horizon and the scheduler

- `horizon` replaces a supervisor program: `restart: unless-stopped` brings it back after a crash
  or a Docker restart.
  - `stop_signal: SIGTERM` is required: the PHP image's default (SIGQUIT) is for PHP-FPM, and
    Horizon ignores it.
  - `stop_grace_period: 660s` gives running jobs time to finish (the longest job timeout is
    600s).
- `init: true` (all four PHP services) runs tini as PID 1, passing signals on to PHP. Without it
  PHP is PID 1, and Linux drops a SIGTERM that arrives before PHP has installed its handler: a
  stop right after a start then waited the whole 660s and ended in SIGKILL.
- **After changing job code:** `docker compose restart horizon`. Long-running workers keep the old
  code in memory.
- `scheduler` runs `schedule:work`, which starts `schedule:run` every minute (today: `model:prune`
  for impersonation tokens). It replaces a cron entry.
  `docker compose exec scheduler php artisan schedule:list` shows what's due.

### Reverb

```
Laravel (app, horizon) ── reverb:8080 (inside Docker) ──────────────────▶ reverb :8080
Browser, port mode ── ws://localhost:8080 (published port) ─────────────▶ reverb :8080
Browser, edge mode ── wss://ws.tenancey.test ── Traefik (TLS ends) ─────▶ reverb :8080
```
- `REVERB_HOST`/`REVERB_PORT` are for PHP, `VITE_REVERB_*` for the browser. After changing
  `VITE_REVERB_*`, rebuild the assets.
- The server binds `0.0.0.0:8080` inside its container (`REVERB_SERVER_*`).
- `docker compose restart reverb` after changing Reverb-related code or settings.

### File storage (MinIO)

New tenants get a bucket on the `minio` container (`FILESYSTEM_DISK=minio`). Tenants recorded on
`local` keep folders under `storage/app/buckets/` and keep working.

**One MinIO, two addresses.** PHP reaches MinIO as `http://minio:9000` (`MINIO_ENDPOINT`). The
browser reaches it at `MINIO_PUBLIC_ENDPOINT`: `http://localhost:9000` (published port) or
`https://s3.tenancey.test` (through Traefik). A download link's signature covers the host, so
links are signed for the public address (`App\Tenancy\Storage\PublicLinkS3Adapter`). The browser's
`Host` header reaches MinIO unchanged (Traefik passes it on), so MinIO checks the signature against
the same host.

- **Image:** `cgr.dev/chainguard/minio`, pinned by digest (Chainguard's free tier only has
  `:latest`). To upgrade: `docker buildx imagetools inspect cgr.dev/chainguard/minio:latest` and
  replace the digest.
- **Dashboard:** `http://localhost:9001` or `https://minio.tenancey.test`
  (`MINIO_BROWSER_REDIRECT_URL` tells it its public address: `MINIO_CONSOLE_URL`, or the edge
  override's value).
- **No healthcheck:** the image is distroless (no shell). Check it from the Mac:
  `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:9000/minio/health/live` (or
  `https://s3.tenancey.test/…` in docker-edge mode) → `200`.
- **Admin work** (e.g. deleting a bucket by hand): use `mc` from its own image on the project's
  network:
  `docker run --rm --network tenancey_default -e MC_HOST_dock=http://minioadmin:minioadmin@minio:9000 cgr.dev/chainguard/minio-client:latest ls dock`.
  Never delete `tenancey-*` buckets by hand: tenant teardown does that.

### Mailpit

Every mail the PHP services send goes to `mailpit:1025`. Nothing leaves your machine. Read the
inbox at http://localhost:8025 (or https://mail.tenancey.test in docker-edge mode).

## Building assets (node)

A one-off `node` container (Node 22.12, as in `.nvmrc`) builds CSS and JS into `public/build`.
Vite reads `VITE_*` from `.env`.
- It has the `tools` profile, so `docker compose up` never starts it.
- A named volume holds `node_modules` inside it, with Linux packages.
- Nothing runs afterwards: nginx serves the built files.
- There is no Vite dev server (HMR). Pages pick up a rebuild on reload.

```bash
docker compose run --rm node                  # npm ci + build: first time, or after package*.json changes
docker compose run --rm node npm run dev      # rebuild only, after CSS/JS/Blade class changes
docker compose run --rm node npm run watch    # rebuild on every change (Ctrl+C stops)
docker volume rm tenancey_node-modules        # reset the packages (refilled on the next run)
```

## Running the tests

```bash
docker compose exec app php artisan test --compact
docker compose exec app php artisan test --compact --group=minio   # against the minio container
```
- `phpunit.xml` pins `APP_URL=https://tenancey.test` and `CENTRAL_DOMAIN=tenancey.test` with
  `<server>` entries. Tests never make real HTTP requests.
- `<server>` writes `$_SERVER`, which Laravel reads first, so it beats `.env`.
  `FILESYSTEM_DISK=local` is a `<server>` entry for the same reason (`.env` says `minio`, and the
  tests make bucket folders).
- `#[Group('minio')]` tests use only `tenanceytest-*` buckets. The host test in
  `PublicEndpointSigningTest` is skipped in Docker.
- The rest comes from `phpunit.xml`: the `tenancey_testing` database, the test prefixes, and Redis
  DBs 13–15.

## Daily use

```bash
bin/setup                                       # fresh clone, or after pulling big changes (safe to re-run)
cd ~/Sites/docker-edge && docker compose up -d  # docker-edge mode, if docker-edge isn't running
docker compose up -d                            # start everything (Docker Desktop restarts it after a reboot anyway)
docker compose ps                               # what's running
docker compose logs -f <service>                # follow one service (Ctrl+C stops following)
docker compose exec app php artisan …           # any artisan command
docker compose restart horizon reverb           # after changing job or Reverb code
docker compose up -d                            # after changing compose.yaml or .env (recreates what changed)
docker compose up -d --build                    # after changing docker/php/
docker compose stop                             # stop for the day, keep the containers
docker compose down                             # remove the containers; data stays in the volumes
```

**`.env` changes** reach PHP-FPM on the next request. Horizon, Reverb and the scheduler are
long-running processes: `docker compose restart horizon reverb scheduler`.

### Resetting the data

Delete tenants in the UI first: their teardown removes databases, users and buckets consistently.
Then:
```bash
docker compose down -v      # deletes the tenancey_* volumes: MySQL, Redis, MinIO and node_modules
docker compose up -d
docker compose exec app php artisan migrate --seed --no-interaction
docker compose run --rm node
```

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "port is already allocated" / "address already in use" | Another program has that port. Change it in `.env`'s "Published ports" block, with the URL that uses it (see Two modes) |
| `acme.localhost:8000` doesn't open | Safari can't resolve `*.localhost`: use Chrome or Firefox. `curl` works too |
| After switching modes or ports, live updates stop | The JS still has the old Reverb address: `docker compose run --rm node` |
| `up` fails: "network edge declared as external, but could not be found" | docker-edge mode without docker-edge running: `cd ~/Sites/docker-edge && docker compose up -d`. Or, for port mode, remove `COMPOSE_FILE` from `.env` |
| `docker compose stop`/`restart` takes minutes, then the container exits 137 | `init: true` is missing from the PHP services (see Horizon): PHP as PID 1 ignored SIGTERM |
| (edge) Traefik's plain `404 page not found` | No router for that host. Look at http://127.0.0.2:18090: is the container up, labelled, on `edge`? |
| `502 Bad Gateway` | The container behind it isn't answering: `docker compose ps`, then its logs |
| Browser certificate warning | `mkcert -install` again. Does `~/Sites/docker-edge/dynamic/tls.yaml` list the certificate? |
| `tenancey.test` goes to Valet or nowhere | `dig +short tenancey.test @127.0.0.1` must say `127.0.0.2`. Check the dnsmasq file and flush the cache: `sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder` |
| Nothing answers on `127.0.0.2` | The forwarders: `sudo launchctl print system/local.edge-forward-443`, `/var/log/local.edge-forward-443.log`, and `ifconfig lo0` (is `127.0.0.2` there?) |
| Links or redirects come out as `http://` | `trustProxies` missing from `bootstrap/app.php`, or the request didn't come through Traefik |
| Images: `SignatureDoesNotMatch` | Links must be signed for the address the browser uses (`MINIO_PUBLIC_ENDPOINT`). After changing it: `docker compose restart horizon` |
| Live progress doesn't update | DevTools → Network → WS should show `ws://localhost:8080` (or `wss://ws.tenancey.test`) with `101`. If it tries another address: fix `VITE_REVERB_*` in `.env`, then `docker compose run --rm node npm run dev`. Is `reverb` up? |
| "Vite manifest not found" | Assets aren't built: `docker compose run --rm node` |
| Pages load assets from `localhost:517x` | A stale `public/hot` file: delete it |
| A tenant stays in "Provisioning" | `docker compose ps`: is `horizon` up? `docker compose logs horizon` |
| Job or Reverb code changes have no effect | `docker compose restart horizon reverb` |
| A `compose.yaml` change has no effect | `docker compose up -d`, not `restart`: settings are fixed when a container is created |
| Pages are slow | Bind-mounted files are slower than the Mac's own disk. Check Docker Desktop → Settings → General uses **VirtioFS** |

## Left over from the Valet setup

Before 2026-10-13 tenancey also ran on Valet. What it left on the Mac is unused, and kept:
- **The Mac's MySQL:** the `tenancey` database, its `tenancey_*` tenant databases and
  `tenancey_testing`.
- **The Mac's MinIO** (`127.0.0.1:19000`, launch agent `local.minio`): Valet tenants' buckets.
  This MinIO is shared with other projects.
- **supervisor:** the `tenancey-horizon` and `tenancey-reverb` programs are stopped, and
  `deploy/supervisor/` is kept for reference.

Deleting any of it is a separate choice. Do it by exact name, never by pattern: the MySQL server
and the MinIO are shared with other projects.
