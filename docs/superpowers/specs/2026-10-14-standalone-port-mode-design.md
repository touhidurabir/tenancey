# Standalone port mode: design

Date: 2026-10-14. Status: **draft, awaiting review**.

## 1. Goal and constraints

**Goal.** Anyone who clones the repo, on any machine with Docker, gets the app running with one
command, `bin/setup`. They don't need Valet, the shared `~/Sites/docker-edge` proxy, mkcert,
DNS changes or `sudo`. The same layout must also serve a GitHub Actions runner later. This Mac
keeps its current setup (docker-edge, `127.0.0.2`, HTTPS on `tenancey.test`) unchanged.

**Decided:**
- **Port mode is the default.** `compose.yaml` publishes each service on a `localhost` port and
  needs no `edge` network or Traefik. `.env.example` holds port-mode values.
- **docker-edge mode is an opt-in override**, `compose.edge.yaml`, turned on only by
  `COMPOSE_FILE=compose.yaml:compose.edge.yaml` in this Mac's `.env`.
- **`.env` is the only place settings live**, published ports included.
- **One setup command**, `bin/setup` (bash), which is safe to run again.

**Out of scope:** the GitHub Actions workflow (next phase), Windows support beyond WSL, HTTPS in
port mode, a Vite dev server, and making `compose.edge.yaml` follow `CENTRAL_DOMAIN`.

## 2. Findings that shaped the design (checked 2026-10-14)

1. **A port in the address is fine for tenancy.**
   - `InitializeTenancyBySubdomain` reads `$request->getHost()`, which drops the port.
   - stancl treats `acme.localhost` as subdomain `acme` of central domain `localhost`.
   - `Tenant::url()` (`app/Models/Tenant.php:203`) adds back the port from `APP_URL`.
   - Laravel's route domain binding ignores the port.
2. **`*.localhost` resolves in Chrome, Firefox and curl, but not through the macOS system
   resolver.** `dscacheutil -q host -a name acme.localhost` returns nothing, so Safari can't open
   tenant pages. Browsers treat `localhost` as a secure context, so plain http is enough there.
3. **Compose v5.5.1 supports `!reset`.** An override can remove ports that the base file
   publishes, instead of adding to them.
4. **`COMPOSE_PROJECT_NAME` beats the top-level `name:`.** A second copy of the repo can run
   beside this Mac's stack without sharing containers or volumes.
5. **This Mac has port clashes.** Another project's Reverb listens on `*:8080`, and this
   project's MySQL already uses 3307.
6. **`DatabaseSeeder` uses `firstOrCreate`**, so seeding again is safe.

## 3. Compose files

### 3.1 `compose.yaml`: port mode

- **Removed:** the `edge` network declaration, `networks: [default, edge]` on every service, and
  every `traefik.*` label. Services use the default network only.
- **Published ports.** Each is bound to `127.0.0.1` and is an `.env` variable with a default
  (names follow Laravel Sail):

| Service | Mapping | Default |
|---|---|---|
| nginx | `127.0.0.1:${APP_PORT:-8000}:80` | 8000 |
| reverb | `127.0.0.1:${FORWARD_REVERB_PORT:-8080}:8080` | 8080 |
| mailpit (inbox) | `127.0.0.1:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025` | 8025 |
| minio (S3 API) | `127.0.0.1:${FORWARD_MINIO_PORT:-9000}:9000` | 9000 |
| minio (dashboard) | `127.0.0.1:${FORWARD_MINIO_CONSOLE_PORT:-9001}:9001` | 9001 |
| mysql | `127.0.0.1:${FORWARD_DB_PORT:-3307}:3306` | 3307 |

- **MinIO dashboard address:** `MINIO_BROWSER_REDIRECT_URL: ${MINIO_CONSOLE_URL:-http://localhost:9001}`.
- **Header comments** describe port mode and point to `compose.edge.yaml` for docker-edge.
- **Unchanged:** services, images, volumes, healthchecks, stop signals and the `node` tools profile.

### 3.2 `compose.edge.yaml`: docker-edge mode (new)

It holds exactly what §3.1 removes:
- **On nginx, reverb, mailpit and minio:**
  - `networks: [default, edge]`
  - the current Traefik labels, unchanged (routers `tenancey` at priority 1, plus `tenancey-ws`,
    `tenancey-mail`, `tenancey-s3` and `tenancey-minio`)
  - `ports: !reset []`
- **On minio:** `MINIO_BROWSER_REDIRECT_URL: https://minio.tenancey.test`.
- **At the bottom:** `networks: edge: external: true` (created by `~/Sites/docker-edge`).

Details:
- Host names stay hard-coded as `tenancey.test`.
- MySQL keeps its `127.0.0.1:3307` port in both modes.
- The `$$` in the HostRegexp label stays, and is checked with `docker inspect`, not
  `docker compose config`.

### 3.3 Laravel

The `trustProxies` comment in `bootstrap/app.php` now covers both modes:
- Behind docker-edge, Traefik sets the `X-Forwarded-*` headers.
- In port mode there is no proxy, so the headers are absent.
- Trusting `*` is acceptable for development because nginx is reachable only through Traefik or
  on `127.0.0.1`.
- Production lists its proxy instead.

No other code changes.

## 4. `.env.example`

Port-mode values:
```
APP_URL=http://localhost:8000
CENTRAL_DOMAIN=localhost
SESSION_SECURE_COOKIE=false
MINIO_PUBLIC_ENDPOINT=http://localhost:9000
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```
- **Port variables:** a "Published ports" block lists the §3.1 variables with their defaults.
  Its note says a changed port must also be changed in the URL that uses it (`APP_URL`,
  `VITE_REVERB_PORT`, `MINIO_PUBLIC_ENDPOINT`, `MINIO_CONSOLE_URL`).
- **docker-edge block:** a commented block lists the values for docker-edge mode:
  - `COMPOSE_FILE=compose.yaml:compose.edge.yaml`
  - `APP_URL=https://tenancey.test`, `CENTRAL_DOMAIN=tenancey.test`, `SESSION_SECURE_COOKIE=true`
  - `MINIO_PUBLIC_ENDPOINT=https://s3.tenancey.test`
  - `VITE_REVERB_HOST=ws.tenancey.test`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`
- **Unchanged:** the service-name settings (`DB_HOST=mysql`, `REDIS_HOST=redis`,
  `MAIL_HOST=mailpit`, `MINIO_ENDPOINT=http://minio:9000`, `REVERB_HOST=reverb`, …).
- **Left empty, filled by `bin/setup`:** `APP_KEY`, `REVERB_APP_ID`, `REVERB_APP_KEY`,
  `REVERB_APP_SECRET`.

This Mac's `.env` gains only the `COMPOSE_FILE` line. The user adds it, and its other values
already match docker-edge mode.

## 5. `bin/setup`

The script is bash, executable, starts with `set -euo pipefail`, and runs from the repo root
(it `cd`s there itself). The host needs only Docker and bash. Every PHP and Node step runs in a
container. Each step prints a numbered heading. A failure stops the script, naming the failed step.

1. **Check Docker.** If `docker compose version` fails, stop with "Docker with Compose v2 is
   required".
2. **Make `.env`.** If there's no `.env`, copy `.env.example`. An existing `.env` is never
   overwritten.
3. **Start the base services.** `docker compose up -d --wait mysql redis minio mailpit app nginx`.
   Horizon, Reverb and the scheduler wait for later, because they exit without `vendor/`.
4. **Install PHP packages.** `docker compose exec -T app composer install --no-interaction`.
5. **Fill the keys, only if empty.**
   - `APP_KEY` empty: `php artisan key:generate --no-interaction`.
   - Each empty `REVERB_APP_ID`, `REVERB_APP_KEY` or `REVERB_APP_SECRET` gets a random value made
     inside the app container.
   - Written with `sed -i.bak` (works on macOS and Linux), then the `.bak` file is removed.
   - A value that is already set is never changed.
   - Secrets are never printed.
6. **Migrate and seed.** `php artisan migrate --force --seed --no-interaction`.
7. **Build the assets.** `docker compose run --rm node` (npm ci + dev build). The `VITE_*` values
   are baked in here.
8. **Start everything.** `docker compose up -d`, then `docker compose restart horizon reverb`.
9. **Print the summary.** It shows the app URL (`APP_URL`), the Mailpit and MinIO dashboard
   addresses, and the admin login `admin@tenancey.test` / `password`. It notes that tenant pages
   on `*.localhost` need Chrome or Firefox, not Safari.

**The script never:**
- runs `config:cache`, `route:cache` or `optimize`
- drops, resets or wipes a database, bucket or volume
- writes outside the project folder
- needs mkcert, docker-edge or `sudo`

It honours `COMPOSE_FILE` and `COMPOSE_PROJECT_NAME` from the environment or `.env`, so it works
unchanged in docker-edge mode.

## 6. Tests and checks

**PHP test (new), written test-first.** A feature test for port mode:
- With `app.url = http://localhost:8000` and `tenancy.central_domains = ['localhost']`, a
  tenant's `url('/login')` is `http://acme.localhost:8000/login`.
- `SubdomainTenantResolver` / the subdomain middleware resolve `acme` from the host
  `acme.localhost:8000`.

The rest of the suite is unchanged. `phpunit.xml` pins `https://tenancey.test` in both modes.

**Fresh-clone check of `bin/setup`.** A copy of the repo in the scratchpad (tracked files plus
the change), run beside this Mac's stack:
- **Isolation:** `COMPOSE_PROJECT_NAME=tenancey-fresh`, so separate containers and volumes.
- **Ports:** its `.env` moves the clashing ones (Reverb, MySQL and any others in use), with the
  matching URLs.
- **Pass criteria:**
  1. `bin/setup` completes.
  2. A second run completes and changes no keys.
  3. The central login page answers 200.
  4. Signing in and creating tenant `acme` works.
  5. `acme.localhost:PORT/login` answers 200.
  6. The Mailpit inbox and the MinIO dashboard answer.
  7. A WebSocket handshake to Reverb gets 101.
- **Clean-up:** the user runs `docker compose -p tenancey-fresh down -v` (it removes only that
  project's volumes).

**This Mac (docker-edge mode).** After the user adds `COMPOSE_FILE` and runs `docker compose up -d`:
- **Routing:** `https://tenancey.test`, a tenant subdomain, `wss://ws.tenancey.test` (101), a
  signed `s3.` link and `mail.` all work as before.
- **No extra ports:** `docker inspect` shows no published ports on nginx, reverb, mailpit and
  minio, and MySQL bound to `127.0.0.1:3307`.
- **Labels:** `docker inspect` shows the Traefik labels with a single `$`.
- **Tests:** full suite green, and Pint clean.

**Compose shape.** `docker compose config` with and without `COMPOSE_FILE` shows the two layouts.

## 7. Documentation

- **`README.md`:** quick start is `git clone`, then `bin/setup`, then `http://localhost:8000`. Plus
  requirements (Docker, bash), the Safari note, and a pointer to docker-edge mode.
- **`docs/DOCKER.md`:**
  - a "Two modes" section, with the port table and how `COMPOSE_FILE` selects the override
  - what `bin/setup` does
  - troubleshooting: port in use (change it in `.env`), Safari and `*.localhost`, rebuild assets
    after switching modes
  - docker-edge content stays as the opt-in section
- **`CLAUDE.md` Environment:** port mode is the default, and this Mac uses docker-edge mode via
  `COMPOSE_FILE`. The commands section mentions `bin/setup`. The boost block is untouched.
- **`docs/STORAGE.md`, `docs/BROADCASTING.md`:** addresses for both modes where they give them.
- **Learning notes** (`docs/docker-learning/`, git-excluded), one part per topic:
  - Compose override merging and `!reset`
  - `COMPOSE_FILE` in `.env`
  - project-name precedence
  - what `bin/setup` does, step by step

## 8. Working rules

- The user runs every `docker`, `git` and `sudo` command that changes state. Claude never commits.
- Never print secrets from `.env`.
- Never drop databases or users, or delete buckets, by pattern. The fresh-clone check uses its
  own Compose project and volumes.
- Never name the private reference projects in repo files.
- Don't touch the other project's Reverb on 8080.
