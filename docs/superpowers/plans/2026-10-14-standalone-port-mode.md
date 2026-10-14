# Standalone Port Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A fresh clone runs with one command (`bin/setup`) on `http://localhost:8000` and
`http://{subdomain}.localhost:8000`, with no Valet, docker-edge, mkcert or `sudo`. This Mac keeps
docker-edge mode through an opt-in Compose override.

**Architecture:**
- `compose.yaml` becomes port mode: every browser-facing service is published on `127.0.0.1`, with
  ports taken from `.env`, and there is no `edge` network.
- `compose.edge.yaml` holds the Traefik labels and the `edge` network, and removes the published
  ports with `!reset`. It is enabled only by `COMPOSE_FILE` in this Mac's `.env`.
- `bin/setup` (bash) bootstraps a clone: `.env`, containers, composer, keys, migrations, assets.

**Tech Stack:** Docker Compose v5.5.1, bash, Laravel 13 / PHP 8.4, PHPUnit, stancl/tenancy v3, Vite.

**Spec:** `docs/superpowers/specs/2026-10-14-standalone-port-mode-design.md`

## Refinements of the spec (found while planning)

- **`Tenant::url()` with a port is already covered.** `TenantUrlTest::test_url_keeps_the_port_from_app_url`
  uses `tenancey.localhost:8000`. The new tests add the two cases the spec needs and nothing
  covers yet:
  - **Single-label central domain:** `localhost`. stancl's `makeSubdomain()` treats a one-part host
    as "not a domain", so `localhost` itself must still be central, and `acme.localhost` must
    still be a tenant.
  - **A real request** to `http://acme.localhost:8000/login` reaching the tenant.
- **These tests are expected to pass on the first run.** Port mode needs no PHP change; the tests
  pin behaviour the mode depends on. A pass on the first run is the expected result here, not a
  finding. Each test is then checked by breaking it on purpose (Task 1, Step 3), to prove it can
  fail.
- **The fresh copy moves every published port, not only the clashing ones** (Task 4). This proves
  that all ports come from `.env`. It also keeps the copy clear of this Mac's Traefik
  (18080/18443/18090) and its MinIO (19000/19001).
- **Order matters on this Mac.** Once `compose.yaml` is port mode, a `docker compose up` without
  `COMPOSE_FILE` would publish 8080 and clash with the other project's Reverb. So the user adds
  `COMPOSE_FILE` to `.env` (Task 2, Step 5) before any `up`.
- **Work goes on a new branch**, `standalone_port_mode`, created by the user from `main`.

## Global Constraints

- **Claude never commits or pushes.** Every "Commit" step is a hand-off: print the commands for the user.
- The user runs every state-changing `docker` command, `bin/setup` itself, and any edit to this
  Mac's `.env`. Claude writes repo files and runs read-only checks: `curl`, `docker compose config`,
  `docker compose ps`, `docker inspect`, `bash -n`, and tests via `docker compose exec app …`.
- Never print secrets from `.env` (`APP_KEY`, `REVERB_APP_SECRET`, `TENANT_DB_KEY`, passwords).
- Never drop databases or users, or delete buckets, by pattern. The fresh copy uses its own Compose
  project, `tenancey-fresh`, and its own volumes.
- Don't touch the other project's Reverb on `*:8080`.
- Never run `config:cache`, `route:cache` or `optimize`.
- Repo files never name the private reference projects.
- Port variables and defaults (Sail names), each bound to `127.0.0.1`:
  - `APP_PORT=8000` → nginx 80
  - `FORWARD_REVERB_PORT=8080` → 8080
  - `FORWARD_MAILPIT_DASHBOARD_PORT=8025` → 8025
  - `FORWARD_MINIO_PORT=9000` → 9000
  - `FORWARD_MINIO_CONSOLE_PORT=9001` → 9001
  - `FORWARD_DB_PORT=3307` → 3306
- `MINIO_BROWSER_REDIRECT_URL` is `${MINIO_CONSOLE_URL:-http://localhost:9001}` in port mode and
  `https://minio.tenancey.test` in edge mode.
- The edge override keeps the current Traefik labels unchanged, with `tenancey.test` hard-coded.
- **Learning series:** each task ends with a note `docs/docker-learning/standalone-<N>-<topic>.md`
  (git-excluded) holding that task's explanation and commands, as taught. Add a row for each note
  to the README table there.

## Review Focus

1. **The edge override leaves a published port behind.** Expect `docker inspect` to show no
   published ports on nginx, reverb, mailpit and minio in edge mode, and MySQL on
   `127.0.0.1:3307`. Checked in Task 2, Step 7.
2. **`$$` in the HostRegexp label after moving it to the override.** Expect a single `$` in
   `docker inspect`. Checked in Task 2, Step 7.
3. **`bin/setup` overwrites a key on a second run.** Expect the `.env` checksum of the key lines to
   be unchanged after the second run. Checked in Task 4, Step 5.
4. **The central domain `localhost` (single label) breaks central or tenant routing.** Expect the
   central login on `localhost:PORT` and the tenant login on `acme.localhost:PORT`. Pinned by Task 1's
   tests and checked live in Task 4, Step 6.
5. **Port mode's browser addresses don't match the published ports** (Reverb, signed MinIO links).
   Expect a WebSocket 101 on the fresh copy's Reverb port and a signed avatar link that opens.
   Checked in Task 4, Steps 6–7.

---

## File map

- `compose.yaml` (modify): port mode. Remove `edge`, the Traefik labels and `networks:`. Add the ports.
- `compose.edge.yaml` (create): edge networks, labels, `ports: !reset []`, the MinIO redirect URL, and the external `edge`.
- `bootstrap/app.php` (modify): the comment above `trustProxies` only.
- `.env.example` (modify): port-mode values, the ports block, and the commented docker-edge block.
- `bin/setup` (create, executable): the bootstrap script.
- `tests/Feature/Tenant/TenantAccessTest.php` (modify): request on `acme.localhost:8000`.
- `tests/Feature/Tenant/TenantUrlTest.php` (modify): the single-label `localhost` URL.
- `README.md`, `docs/DOCKER.md`, `CLAUDE.md`, `docs/STORAGE.md`, `docs/BROADCASTING.md` (modify): docs.
- `docs/docker-learning/standalone-*.md` and `docs/docker-learning/README.md`: learning notes (git-excluded).

---

### Task 1: Pin port-mode routing with tests, and fix the proxy comment

**Files:**
- Modify: `tests/Feature/Tenant/TenantUrlTest.php`
- Modify: `tests/Feature/Tenant/TenantAccessTest.php`
- Modify: `bootstrap/app.php:16-18`

**Interfaces:**
- Consumes: `TestCase::createTenant()`, `Tenant::url()`.
- Produces: nothing used by later tasks.

- [ ] **Step 1: Add the URL test for the single-label central domain**

In `TenantUrlTest`, after `test_url_keeps_the_port_from_app_url`:

```php
    public function test_url_on_the_bare_localhost_central_domain(): void
    {
        config(['app.url' => 'http://localhost:8000', 'tenancy.central_domains' => ['localhost']]);

        $this->assertSame('http://acme.localhost:8000/login', $this->acme()->url('/login'));
    }
```

- [ ] **Step 2: Add the request test**

In `TenantAccessTest`, after `test_tenant_routes_do_not_exist_on_the_central_domain`:

```php
    /**
     * Port mode (compose.yaml without docker-edge): the central domain is `localhost` and the
     * address carries a port. The tenant is still found from the host's first label.
     */
    public function test_a_tenant_answers_on_a_localhost_subdomain_with_a_port(): void
    {
        $this->createTenant('acme');
        tenancy()->end();
        config(['app.url' => 'http://localhost:8000', 'tenancy.central_domains' => ['localhost']]);

        $this->get('http://acme.localhost:8000/login')->assertOk()->assertSee('Sign in to Acme Ltd');
    }
```

- [ ] **Step 3: Run both, then prove each can fail**

Run: `docker compose exec app php artisan test --compact --filter='test_url_on_the_bare_localhost_central_domain|test_a_tenant_answers_on_a_localhost_subdomain_with_a_port'`
Expected: 2 passed. Port mode needs no code change (see Refinements).

Then break each test on purpose and run it again:
- Change `'localhost'` in the request test's `central_domains` to `'example.test'`.
  Expected: FAIL. The host is a "third party domain" and gets 404 "No such workspace".
- Change `:8000` in the URL test's `app.url` to `:9999`.
  Expected: FAIL on the URL.

Revert both edits and run the filter again. Expected: 2 passed.

- [ ] **Step 4: Update the proxy comment in `bootstrap/app.php`**

Replace lines 16–18 with:

```php
        // Behind docker-edge (compose.edge.yaml), Traefik ends HTTPS and forwards http with
        // X-Forwarded-Proto/Host/Port. In port mode there is no proxy and the headers are absent.
        // nginx is reachable only through Traefik or on 127.0.0.1, so trusting any proxy is fine
        // for development; production lists its proxy's address instead.
```

- [ ] **Step 5: Full suite and Pint**

Run: `docker compose exec app php artisan test --compact`
Expected: all pass (116 passed, 1 skipped: the previous 114 plus 2).

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Expected: no changes, or formatting fixes only.

- [ ] **Step 6: Learning note and hand-off**

Write `docs/docker-learning/standalone-1-port-mode-routing.md`. It explains:
- how stancl turns a host into a subdomain, and why the port doesn't matter
- why a pass on the first run is fine for a pinning test, and how breaking it on purpose proves
  it can fail

Add its row to the README table.

Print for the user:
```bash
git add tests/Feature/Tenant/TenantUrlTest.php tests/Feature/Tenant/TenantAccessTest.php bootstrap/app.php
git commit -m "Tests pinning port-mode routing on localhost with a port"
```

---

### Task 2: Split Compose into port mode and the docker-edge override

**Files:**
- Modify: `compose.yaml`
- Create: `compose.edge.yaml`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - The port variables (Global Constraints) and `MINIO_CONSOLE_URL`, read from `.env` by Compose.
  - `COMPOSE_FILE=compose.yaml:compose.edge.yaml` selects edge mode.
  - Tasks 3 and 4 rely on these names.

- [ ] **Step 1: Save the current shape for comparison**

Run: `docker compose config --format json > "$SCRATCH/compose-before.json"`, where `SCRATCH` is
the session scratchpad.
Expected: the file is written. It records today's labels and networks (edge mode).

- [ ] **Step 2: Rewrite `compose.yaml` as port mode**

**Header:**

```yaml
# Local development for tenancey. Guide: docs/DOCKER.md
# Port mode (default): http://localhost:8000, tenants at http://{subdomain}.localhost:8000.
# docker-edge mode (HTTPS on tenancey.test): add COMPOSE_FILE=compose.yaml:compose.edge.yaml to .env.
name: tenancey
# Laravel settings live in .env, and so do the published ports below.
```

**reverb:**
- Remove `networks:` and `labels:`.
- Add:
  ```yaml
      ports:
        - "127.0.0.1:${FORWARD_REVERB_PORT:-8080}:8080" # browser: ws://localhost:8080; PHP containers: reverb:8080
  ```

**nginx:**
- Remove `networks:` and `labels:`.
- Add:
  ```yaml
      ports:
        - "127.0.0.1:${APP_PORT:-8000}:80" # the central domain and every tenant
  ```

**mysql:** the port becomes:
```yaml
    ports:
      - "127.0.0.1:${FORWARD_DB_PORT:-3307}:3306" # this machine only; 3307 so a local MySQL can keep 3306
```

**mailpit:**
- Remove `networks:` and `labels:`.
- Add:
  ```yaml
      ports:
        - "127.0.0.1:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025" # inbox (SMTP 1025 stays inside the network)
  ```

**minio:**
- Remove `networks:` and `labels:`.
- Change the redirect URL and add ports:
  ```yaml
        MINIO_BROWSER_REDIRECT_URL: ${MINIO_CONSOLE_URL:-http://localhost:9001} # the dashboard's public address
  ```
  ```yaml
      ports:
        - "127.0.0.1:${FORWARD_MINIO_PORT:-9000}:9000" # S3 API, what links are signed for (MINIO_PUBLIC_ENDPOINT)
        - "127.0.0.1:${FORWARD_MINIO_CONSOLE_PORT:-9001}:9001" # dashboard
  ```

**Bottom of the file:** delete the whole `networks:` block.

- [ ] **Step 3: Create `compose.edge.yaml`**

```yaml
# docker-edge mode: HTTPS on tenancey.test through the shared Traefik proxy (~/Sites/docker-edge,
# start it first). Turned on by COMPOSE_FILE=compose.yaml:compose.edge.yaml in .env; it then
# applies to every `docker compose` command. Guide: docs/DOCKER.md ("Two modes").
#
# It adds the Traefik labels and the edge network, and removes compose.yaml's published ports
# (`!reset`), so nothing else on this machine is taken (another project may hold 8080). MySQL
# keeps its 127.0.0.1 port.

services:
  reverb:
    networks: [default, edge] # the browser: wss://ws.tenancey.test → Traefik; PHP containers: reverb:8080
    ports: !reset []
    labels:
      traefik.enable: "true"
      traefik.docker.network: edge
      traefik.http.routers.tenancey-ws.rule: Host(`ws.tenancey.test`)
      traefik.http.routers.tenancey-ws.entrypoints: websecure
      traefik.http.routers.tenancey-ws.priority: "100"
      traefik.http.services.tenancey-ws.loadbalancer.server.port: "8080"

  nginx:
    networks: [default, edge]
    ports: !reset []
    labels:
      traefik.enable: "true"
      traefik.docker.network: edge
      # The central domain and every tenant. Priority 1, so the named side services win
      # (Traefik's default priority is the rule's length, and this rule is the longest).
      traefik.http.routers.tenancey.rule: 'Host(`tenancey.test`) || HostRegexp(`^[a-z0-9-]+\.tenancey\.test$$`)'
      traefik.http.routers.tenancey.entrypoints: websecure
      traefik.http.routers.tenancey.priority: "1"
      traefik.http.services.tenancey.loadbalancer.server.port: "80"

  mailpit:
    networks: [default, edge] # inbox: https://mail.tenancey.test
    ports: !reset []
    labels:
      traefik.enable: "true"
      traefik.docker.network: edge
      traefik.http.routers.tenancey-mail.rule: Host(`mail.tenancey.test`)
      traefik.http.routers.tenancey-mail.entrypoints: websecure
      traefik.http.routers.tenancey-mail.priority: "100"
      traefik.http.services.tenancey-mail.loadbalancer.server.port: "8025"

  minio:
    environment:
      MINIO_BROWSER_REDIRECT_URL: https://minio.tenancey.test
    networks: [default, edge] # S3 API: https://s3.tenancey.test   dashboard: https://minio.tenancey.test
    ports: !reset []
    labels:
      traefik.enable: "true"
      traefik.docker.network: edge
      traefik.http.routers.tenancey-s3.rule: Host(`s3.tenancey.test`)
      traefik.http.routers.tenancey-s3.entrypoints: websecure
      traefik.http.routers.tenancey-s3.priority: "100"
      traefik.http.routers.tenancey-s3.service: tenancey-s3
      traefik.http.services.tenancey-s3.loadbalancer.server.port: "9000"
      traefik.http.routers.tenancey-minio.rule: Host(`minio.tenancey.test`)
      traefik.http.routers.tenancey-minio.entrypoints: websecure
      traefik.http.routers.tenancey-minio.priority: "100"
      traefik.http.routers.tenancey-minio.service: tenancey-minio
      traefik.http.services.tenancey-minio.loadbalancer.server.port: "9001"

networks:
  edge:
    external: true # created by ~/Sites/docker-edge
```

- [ ] **Step 4: Check both shapes (read-only)**

Run: `COMPOSE_FILE=compose.yaml docker compose config --format json > "$SCRATCH/port.json"`, then
use `jq` to check:
- `.services.nginx.ports[0]` is host_ip 127.0.0.1, published 8000, target 80.
- reverb publishes 8080, mailpit 8025, minio 9000 and 9001, mysql 3307.
- `.networks` has no `edge`.
- No service has a `traefik.enable` label.

Run: `COMPOSE_FILE=compose.yaml:compose.edge.yaml docker compose config --format json > "$SCRATCH/edge.json"`, then:
- `jq '.services | map_values(.ports)'`: no ports on nginx, reverb, mailpit or minio, and mysql
  has `127.0.0.1:3307`.
- `jq -S '[.services[] | .labels] '` matches the same expression on `compose-before.json`.
- The networks match too.
- `.services.minio.environment.MINIO_BROWSER_REDIRECT_URL` is `https://minio.tenancey.test`.

Expected: all match. Edge mode is identical to today except MySQL, which was already `127.0.0.1:3307`.

- [ ] **Step 5: Hand the switch to the user**

Tell the user to add this line near the top of `.env` (the edit is theirs):
```
COMPOSE_FILE=compose.yaml:compose.edge.yaml
```
Then to run:
```bash
docker compose up -d
```
Expected: nginx, reverb, mailpit, minio and mysql are recreated. Nothing fails on 8080.

- [ ] **Step 6: Live checks in edge mode (read-only)**

Run:
```bash
docker compose ps
curl -s -o /dev/null -w '%{http_code}\n' https://tenancey.test/login
curl -s -o /dev/null -w '%{http_code}\n' https://mail.tenancey.test/
curl -s -o /dev/null -w '%{http_code}\n' https://minio.tenancey.test/
```
Expected: all services Up, then 200, 200, 200.

Run a WebSocket handshake. The key is public: it's baked into the JS. Don't print it.
```bash
KEY=$(grep '^REVERB_APP_KEY=' .env | cut -d= -f2-)
curl -s -o /dev/null -w '%{http_code}\n' --http1.1 -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  --max-time 3 "https://ws.tenancey.test/app/$KEY"
```
Expected: `101`. curl exits on the timeout after the upgrade; the code is what counts.

The user opens a tenant page and an avatar link (a signed `s3.` link) in the browser.
Expected: both work.

- [ ] **Step 7: Inspect ports and labels (Review Focus 1, 2)**

Run:
```bash
for s in nginx reverb mailpit minio mysql; do printf '%s ' $s; docker inspect "tenancey-$s-1" --format '{{json .HostConfig.PortBindings}}'; done
docker inspect tenancey-nginx-1 --format '{{index .Config.Labels "traefik.http.routers.tenancey.rule"}}'
```
Expected:
- `{}` for the first four.
- mysql is `{"3306/tcp":[{"HostIp":"127.0.0.1","HostPort":"3307"}]}`.
- The rule ends with `tenancey\.test$`)`, a single `$`.

- [ ] **Step 8: Full suite**

Run: `docker compose exec app php artisan test --compact`
Expected: 116 passed, 1 skipped.

- [ ] **Step 9: Learning note and hand-off**

Write `docs/docker-learning/standalone-2-compose-override.md`. It covers:
- how Compose merges several files: maps merge, lists append, and `!reset` empties
- `COMPOSE_FILE` in `.env`
- `docker compose config` as the merged result
- why the switch had to come before `up`

Add its row to the README table, including new command rows for `docker compose config --format json`.

Print for the user:
```bash
git add compose.yaml compose.edge.yaml
git commit -m "Compose: port mode by default, docker-edge as an opt-in override"
```

---

### Task 3: `.env.example` and `bin/setup`

**Files:**
- Modify: `.env.example`
- Create: `bin/setup` (mode 755)

**Interfaces:**
- Consumes: the port variables and `COMPOSE_FILE` from Task 2.
- Produces: `bin/setup`, run with no arguments from anywhere. Task 4 runs it.

- [ ] **Step 1: Port-mode values in `.env.example`**

Change these lines:
- `APP_URL=https://tenancey.test` → `APP_URL=http://localhost:8000`
- `CENTRAL_DOMAIN=tenancey.test` → `CENTRAL_DOMAIN=localhost`
- `SESSION_SECURE_COOKIE=true` → `SESSION_SECURE_COOKIE=false`
- `MINIO_PUBLIC_ENDPOINT=https://s3.tenancey.test` → `MINIO_PUBLIC_ENDPOINT=http://localhost:9000`
- `VITE_REVERB_HOST=ws.tenancey.test` → `VITE_REVERB_HOST=localhost`
- `VITE_REVERB_PORT=443` → `VITE_REVERB_PORT=8080`
- `VITE_REVERB_SCHEME=https` → `VITE_REVERB_SCHEME=http`

Change two comments:
- The MinIO comment's "through Traefik" becomes "(MINIO_PUBLIC_ENDPOINT)".
- The Reverb comment's "(through Traefik)" becomes "(the published port, or Traefik in docker-edge mode)".

- [ ] **Step 2: The ports and docker-edge blocks**

Insert after `APP_URL=…` and its blank line:

```
# Published ports (compose.yaml, bound to 127.0.0.1). Port mode: http://localhost:APP_PORT,
# tenants at http://{subdomain}.localhost:APP_PORT (Chrome or Firefox; Safari can't resolve
# *.localhost). If you change a port, change the URL that uses it too: APP_URL (APP_PORT),
# VITE_REVERB_PORT (FORWARD_REVERB_PORT), MINIO_PUBLIC_ENDPOINT (FORWARD_MINIO_PORT),
# MINIO_CONSOLE_URL (FORWARD_MINIO_CONSOLE_PORT). Rebuild assets after a VITE_* change.
APP_PORT=8000
FORWARD_REVERB_PORT=8080
FORWARD_MAILPIT_DASHBOARD_PORT=8025
FORWARD_MINIO_PORT=9000
FORWARD_MINIO_CONSOLE_PORT=9001
FORWARD_DB_PORT=3307
MINIO_CONSOLE_URL=http://localhost:9001

# docker-edge mode (HTTPS on tenancey.test through ~/Sites/docker-edge, see docs/DOCKER.md):
# uncomment COMPOSE_FILE and use these values instead of the port-mode ones in this file.
# COMPOSE_FILE=compose.yaml:compose.edge.yaml
# APP_URL=https://tenancey.test
# CENTRAL_DOMAIN=tenancey.test
# SESSION_SECURE_COOKIE=true
# MINIO_PUBLIC_ENDPOINT=https://s3.tenancey.test
# VITE_REVERB_HOST=ws.tenancey.test
# VITE_REVERB_PORT=443
# VITE_REVERB_SCHEME=https
```

Change the Reverb comment "Generate the app id/key/secret with `php artisan reverb:install` or any
random strings" to "bin/setup fills the empty app id/key/secret with random values".

- [ ] **Step 3: Write `bin/setup`**

```bash
#!/usr/bin/env bash
# One-command setup for a fresh clone (and safe to run again): .env, containers, PHP packages,
# keys, database, assets. Needs only Docker (Compose v2) and bash. Guide: docs/DOCKER.md.
set -euo pipefail

cd "$(dirname "$0")/.."

step=""
trap 'echo; echo "bin/setup stopped at: $step" >&2' ERR

say() {
    step="$1"
    echo
    echo "==> $1"
}

# The value of KEY in .env (empty if unset).
env_value() {
    grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

# Set KEY in .env, only when it is present and empty.
fill_if_empty() {
    if grep -qE "^$1=$" .env; then
        sed -i.bak "s|^$1=\$|$1=$2|" .env && rm -f .env.bak
        echo "$1 set"
    fi
}

say "1/9 Checking Docker"
if ! docker compose version > /dev/null 2>&1; then
    echo "Docker with Compose v2 is required (https://docs.docker.com/get-docker/)." >&2
    exit 1
fi

say "2/9 Making .env"
if [ -f .env ]; then
    echo ".env exists, kept as it is"
else
    cp .env.example .env
    echo ".env created from .env.example"
fi

say "3/9 Starting MySQL, Redis, MinIO, Mailpit, PHP and nginx"
docker compose up -d --wait mysql redis minio mailpit app nginx

say "4/9 Installing PHP packages"
docker compose exec -T app composer install --no-interaction

say "5/9 Filling empty keys"
if grep -qE '^APP_KEY=$' .env; then
    docker compose exec -T app php artisan key:generate --no-interaction
fi
fill_if_empty REVERB_APP_ID "$(docker compose exec -T app php -r 'echo random_int(100000, 999999);')"
fill_if_empty REVERB_APP_KEY "$(docker compose exec -T app php -r 'echo bin2hex(random_bytes(16));')"
fill_if_empty REVERB_APP_SECRET "$(docker compose exec -T app php -r 'echo bin2hex(random_bytes(32));')"

say "6/9 Migrating and seeding the central database"
docker compose exec -T app php artisan migrate --force --seed --no-interaction

say "7/9 Building assets"
docker compose run --rm -T node

say "8/9 Starting Horizon, Reverb and the scheduler"
docker compose up -d
docker compose restart horizon reverb

say "9/9 Done"
cat <<EOF

  App:      $(env_value APP_URL)   (admin@tenancey.test / password)
  Mail:     http://localhost:$(env_value FORWARD_MAILPIT_DASHBOARD_PORT)
  MinIO:    $(env_value MINIO_CONSOLE_URL)   (minioadmin / minioadmin)

  Tenants live at {subdomain}.$(env_value CENTRAL_DOMAIN). On *.localhost use Chrome or Firefox:
  Safari can't resolve those names. In docker-edge mode the addresses are in docs/DOCKER.md.
EOF
```

Then: `chmod 755 bin/setup`.

- [ ] **Step 4: Static checks**

Run: `bash -n bin/setup && echo ok`
Expected: `ok`.

Run: `docker run --rm -v "$PWD/bin:/mnt:ro" koalaman/shellcheck:stable /mnt/setup` (read-only; the
user runs it, since it pulls an image).
Expected: no warnings, or only SC2155-style notes, each fixed or justified in a comment.

Run: `diff <(grep -oE '^[A-Z_]+=' .env.example | sort) <(grep -oE '^[A-Z_]+=' .env | sort)`
Expected: the only differences are the new port lines and `MINIO_CONSOLE_URL`, which this Mac's
`.env` doesn't need in edge mode, plus `COMPOSE_FILE`. These are names only, no values.

- [ ] **Step 5: Learning note and hand-off**

Write `docs/docker-learning/standalone-3-bin-setup.md`. It walks through the script line by line:
- `set -euo pipefail` and the `ERR` trap
- `up --wait`
- `exec -T`
- why each step is safe to run again

Add its row to the README table.

Print for the user:
```bash
git add .env.example bin/setup
git commit -m "bin/setup and port-mode .env.example for a one-command fresh clone"
```

---

### Task 4: Fresh-clone run beside this Mac's stack

**Files:** none in the repo. Everything happens in `$SCRATCH/tenancey-fresh`.

**Interfaces:**
- Consumes: `bin/setup`, `compose.yaml` and `.env.example` from Tasks 2–3.
- Produces: evidence only.

- [ ] **Step 1: Copy the working tree (tracked and new files, nothing ignored)**

Run:
```bash
mkdir -p "$SCRATCH/tenancey-fresh"
git ls-files -co --exclude-standard -z | rsync -a --from0 --files-from=- ./ "$SCRATCH/tenancey-fresh/"
ls "$SCRATCH/tenancey-fresh" | head; test ! -e "$SCRATCH/tenancey-fresh/.env" && test ! -e "$SCRATCH/tenancey-fresh/vendor" && echo clean
```
Expected: `clean`. There's no `.env`, `vendor` or `node_modules`, the way a fresh clone looks.

- [ ] **Step 2: Write the copy's `.env` from `.env.example` with moved ports**

Run, in `$SCRATCH/tenancey-fresh`:
```bash
cp .env.example .env
sed -i.bak \
  -e 's|^APP_URL=.*|APP_URL=http://localhost:28000|' \
  -e 's|^APP_PORT=.*|APP_PORT=28000|' \
  -e 's|^FORWARD_REVERB_PORT=.*|FORWARD_REVERB_PORT=28080|' \
  -e 's|^VITE_REVERB_PORT=.*|VITE_REVERB_PORT=28080|' \
  -e 's|^FORWARD_MAILPIT_DASHBOARD_PORT=.*|FORWARD_MAILPIT_DASHBOARD_PORT=28025|' \
  -e 's|^FORWARD_MINIO_PORT=.*|FORWARD_MINIO_PORT=29000|' \
  -e 's|^MINIO_PUBLIC_ENDPOINT=.*|MINIO_PUBLIC_ENDPOINT=http://localhost:29000|' \
  -e 's|^FORWARD_MINIO_CONSOLE_PORT=.*|FORWARD_MINIO_CONSOLE_PORT=29001|' \
  -e 's|^MINIO_CONSOLE_URL=.*|MINIO_CONSOLE_URL=http://localhost:29001|' \
  -e 's|^FORWARD_DB_PORT=.*|FORWARD_DB_PORT=23307|' .env && rm .env.bak
printf '\nCOMPOSE_PROJECT_NAME=tenancey-fresh\n' >> .env
docker compose config --format json | jq -r '.name, (.services.nginx.ports[0].published)'
```
Expected: `tenancey-fresh` then `28000`. The project name from `.env` beats `name:`.

Check the ports are free: `lsof -nP -iTCP -sTCP:LISTEN | grep -E ':(28000|28080|28025|29000|29001|23307) '`
Expected: no output.

- [ ] **Step 3: The user runs `bin/setup` in the copy**

Print for the user: `cd "$SCRATCH/tenancey-fresh" && bin/setup`
Expected: the steps 1/9 to 9/9 print. The summary shows `App: http://localhost:28000`.

- [ ] **Step 4: Fingerprint the keys (no values printed)**

Run: `grep -E '^(APP_KEY|REVERB_APP_ID|REVERB_APP_KEY|REVERB_APP_SECRET)=' .env | shasum -a 256 > "$SCRATCH/keys.sha"; grep -cE '^(APP_KEY|REVERB_APP_ID|REVERB_APP_KEY|REVERB_APP_SECRET)=.+' .env`
Expected: `4`, meaning all four keys are filled.

- [ ] **Step 5: The user runs `bin/setup` again (Review Focus 3)**

Expected: completes. Step 2 says ".env exists, kept as it is". No "… set" lines. Migrate says
"Nothing to migrate".

Run: `grep -E '^(APP_KEY|REVERB_APP_ID|REVERB_APP_KEY|REVERB_APP_SECRET)=' .env | shasum -a 256 | diff - "$SCRATCH/keys.sha" && echo unchanged`
Expected: `unchanged`.

- [ ] **Step 6: Live checks (Review Focus 4, 5)**

Run:
```bash
docker compose ps --format '{{.Service}} {{.State}}'
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:28000/login
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:28025/
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:29001/
KEY=$(grep '^REVERB_APP_KEY=' .env | cut -d= -f2-)
curl -s -o /dev/null -w '%{http_code}\n' --http1.1 -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  --max-time 3 "http://localhost:28080/app/$KEY"
```
Expected: every service running, then 200, 200, 200, 101.

In Chrome, the user:
1. Signs in at `http://localhost:28000`.
2. Creates tenant `acme` and watches its page go Ready without a reload. That proves Reverb on
   28080 from the browser.

Then run: `curl -s -o /dev/null -w '%{http_code}\n' http://acme.localhost:28000/login`
Expected: `200`.

- [ ] **Step 7: Signed MinIO link**

The user:
1. Opens the welcome mail in Mailpit (`http://localhost:28025`) and sets the tenant admin's password.
2. Uploads an avatar on `http://acme.localhost:28000`.
3. Opens it.

Expected: the image loads from `http://localhost:29000/...` (no `SignatureDoesNotMatch`).

- [ ] **Step 8: Clean-up, run by the user**

Print:
```bash
cd "$SCRATCH/tenancey-fresh" && docker compose down -v && cd - && rm -rf "$SCRATCH/tenancey-fresh"
```
Expected: only the `tenancey-fresh-*` containers, network and volumes are removed.

Then check the main stack is untouched: `docker compose ps` in the repo shows all services Up.

- [ ] **Step 9: Learning note**

Write `docs/docker-learning/standalone-4-fresh-clone.md`. It covers:
- copying a clean tree with `git ls-files`
- how `COMPOSE_PROJECT_NAME` beats `name:`
- running two copies side by side
- what each check proved

Add its row to the README table. There's nothing to commit.

---

### Task 5: Documentation

**Files:**
- Modify: `README.md` ("Requirements", "Setup (local, Docker)", "Common problems")
- Modify: `docs/DOCKER.md` (intro table, new "Two modes", "First run", "Troubleshooting")
- Modify: `CLAUDE.md` (Environment, Commands; not the boost block)
- Modify: `docs/STORAGE.md`, `docs/BROADCASTING.md` (addresses)

**Interfaces:**
- Consumes: everything above.
- Produces: docs only.

- [ ] **Step 1: `README.md`**

**"Requirements":** Docker Desktop (or Docker Engine) with Compose v2, and bash. Remove any
statement that docker-edge or mkcert is required. They are listed under "Optional: HTTPS on
`tenancey.test`", with a link to `docs/DOCKER.md#two-modes`.

**"Setup (local, Docker)":**
```bash
git clone <repo> tenancey && cd tenancey
bin/setup
```
- Then open `http://localhost:8000` (`admin@tenancey.test` / `password`). Tenants are at
  `http://{subdomain}.localhost:8000`.
- One sentence on the Safari limit.
- One sentence: "Ports already in use? Change them in `.env` (see the "Published ports" block) and
  run `bin/setup` again."
- The old manual REVERB/APP_KEY steps are removed, since `bin/setup` does them.

**"Common problems":** add rows for:
- a port in use
- a Safari tenant page that doesn't open
- a stale Reverb address after changing `VITE_*`: run `docker compose run --rm node`

- [ ] **Step 2: `docs/DOCKER.md`**

**Intro:**
- Two modes in one paragraph.
- The address table becomes two columns, "Port mode (default)" and "docker-edge mode", using the
  values from the spec §3 and today's table.
- The Traefik dashboard row is for edge mode only.

**New "## Two modes" section, after the intro:**
- what each mode is and who it's for
- the published-ports table with variables
- how `COMPOSE_FILE` turns on `compose.edge.yaml` and what the override changes (labels, `edge`,
  `!reset` ports)
- switching modes: change the `.env` values listed in `.env.example`'s docker-edge block, then run
  `docker compose up -d` and `docker compose run --rm node`

"How a request gets in" and "One-time Mac setup" get a first line: "docker-edge mode only".

**"First run of the project":** `bin/setup`, with a numbered list of what it does (the spec §5,
steps 1–9). It says the script is safe to run again.

**"Troubleshooting":** port in use; Safari and `*.localhost`; assets still point at the old Reverb
address (rebuild); "network edge declared as external, but could not be found" (start docker-edge,
or remove `COMPOSE_FILE`).

- [ ] **Step 3: `CLAUDE.md`**

**Environment:**
- First bullet: Docker only. Port mode is the default (`http://localhost:8000`, tenants
  `http://{subdomain}.localhost:8000`, set up with `bin/setup`).
- **This Mac** runs docker-edge mode (`COMPOSE_FILE=compose.yaml:compose.edge.yaml` in `.env`).
  The existing `https://tenancey.test` bullet stays, as this Mac's addresses.
- The `.env` bullet adds: "and the published ports".

**Commands:** add `bin/setup  # fresh clone: .env, containers, keys, DB, assets (safe to re-run)`
first, and change the `up -d` comment to "(edge mode on this Mac: docker-edge must be up)".

- [ ] **Step 4: `docs/STORAGE.md` and `docs/BROADCASTING.md`**

Wherever they give `https://s3.tenancey.test`, `https://minio.tenancey.test` or
`wss://ws.tenancey.test`, add the port-mode address next to it (`http://localhost:9000`,
`http://localhost:9001`, `ws://localhost:8080`).

Run: `grep -n 'tenancey.test' docs/STORAGE.md docs/BROADCASTING.md` before and after.
Expected: each address line now names both modes.

- [ ] **Step 5: Consistency checks**

Run:
```bash
grep -rn 'edge.*external\|docker-edge must\|requires docker-edge' README.md docs/DOCKER.md CLAUDE.md
grep -rn 'reverb:install' README.md docs/DOCKER.md .env.example
```
Expected:
- The first grep finds only lines inside edge-mode context.
- The second grep finds nothing that tells the reader to generate keys by hand.

Run the full suite once more: `docker compose exec app php artisan test --compact`
Expected: 116 passed, 1 skipped.

- [ ] **Step 6: Learning note and hand-off**

Write `docs/docker-learning/standalone-5-wrap-up.md`: the two modes on one page, and which file
does what. Add its row to the README table.

Print for the user:
```bash
git add README.md docs/DOCKER.md CLAUDE.md docs/STORAGE.md docs/BROADCASTING.md
git commit -m "Docs: port mode by default, docker-edge as an option, bin/setup"
```
