# Docker-only HTTPS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve tenancey only from Docker at `https://tenancey.test` and `https://{subdomain}.tenancey.test`, through a shared Traefik proxy on `127.0.0.2`, and remove the Valet setup from the repo.

**Architecture:** Two root socat daemons forward `127.0.0.2:80/443` to Traefik's published `127.0.0.2:18080/18443`. Traefik lives in its own Compose project (`~/Sites/docker-edge`), owns the `edge` network, serves mkcert certificates, and routes by host name using Docker labels. tenancey's browser-facing containers (nginx, reverb, minio, mailpit) join `edge`. Laravel trusts the proxy's `X-Forwarded-*` headers. A dnsmasq line sends only `tenancey.test` to `127.0.0.2`; Valet keeps its other sites.

**Tech Stack:** macOS launchd + socat 1.8, mkcert, Traefik v3.7.14, Docker Compose, Laravel 13 / PHP 8.4, PHPUnit, Vite 8.

**Spec:** `docs/superpowers/specs/2026-10-13-docker-only-https-design.md`

## Refinements of the spec (found while planning)

- **The `.env` move happens after the switch (Task 6), not with the other tenancey changes.**
  Valet's PHP and supervisor programs read `.env`. Changing it in Task 4 would break Valet before
  DNS moves. So Task 4 changes only `x-laravel-env`.
- **No signed-link proxy test.** The `tenant.storage.local` links are signed **relative**
  (`absolute: false`), and impersonation uses a token, not a signed URL. So the scheme can't break
  either one. `TrustedProxyTest` covers what can break: absolute URLs built during a request.
- **`TenantUrlTest` stays as it is.** Its tests set `app.url` themselves and already cover the
  https case. What changes is the tests that inherit the `APP_URL` pin
  (`ProvisioningPipelineTest`, `ImpersonationTest`, the `TestCase` helpers).
- **Only `minio` is a red test for reserved names.** `s3` and `ws` are already refused by
  `min:3`, but they still go in the constant.
- **The launchd files are versioned in `docker-edge/mac/`,** so what is installed in
  `/Library/LaunchDaemons` has a source.

## Global Constraints

- **Claude never commits or pushes.** Every "Commit" step is a hand-off: print the commands for the user.
- The user runs every `docker`, `sudo`, `valet`, `supervisorctl`, `brew` and `mkcert` command. Claude writes files and runs read-only checks (`curl`, `netstat`, `dig`, `docker compose config`, `docker compose ps`).
- Never touch the other project's Reverb (`*:8080`) or supervisor programs. Stop only `tenancey-horizon` and `tenancey-reverb`.
- Never drop databases or users by pattern. On MinIO, only `tenanceytest-*` buckets may be deleted by pattern. Valet's data (the Mac's MySQL `tenancey*` databases, the Mac's MinIO buckets) stays untouched.
- Never run `config:cache`, `route:cache` or `optimize`.
- Repo files never name the private reference projects.
- Ports: the forwarders listen on `127.0.0.2:80` and `:443` and forward to Traefik's `127.0.0.2:18080` and `:18443`. The dashboard is on `127.0.0.2:18090`. MySQL stays `127.0.0.1:3307`.
- Traefik image: `traefik:v3.7.14@sha256:575fa15b135078fe5e50aa847987d96dbddd7b093c172429618404df73f3fa7c`.
- Host names: `tenancey.test`, `*.tenancey.test`, `ws.tenancey.test` (Reverb), `s3.tenancey.test` (MinIO API), `minio.tenancey.test` (MinIO dashboard), `mail.tenancey.test` (Mailpit).
- **Learning series:** each task ends with a learning note `docs/docker-learning/https-<N>-<topic>.md` (git-excluded) holding the task's explanation and commands as taught. Add each note to the README table there.
- **Valet keeps working until Task 5.** No change to `.env` before then: Valet's PHP and the supervisor programs read it.

## Review Focus

1. **Router priority.** `ws.`, `s3.`, `minio.` and `mail.` must not be captured by the tenant wildcard router. Expect each host to reach its own service. Checked per host in Task 4, Step 7.
2. **`$` escaping in Compose labels.** `HostRegexp(...$)` must reach Traefik with a single `$`. Expect `docker compose config` to show `test$`. Checked in Task 4, Step 3.
3. **MinIO signed links through Traefik.** A presigned `https://s3.tenancey.test/...` link must open (not `SignatureDoesNotMatch`). Checked in the Task 5 walkthrough (avatar).
4. **Reverb over `wss` through Traefik.** The live provisioning page must update without reload. Checked in the Task 5 walkthrough.
5. **Moving settings from `compose.yaml` to `.env` loses a value.** Expect the container's effective config to be unchanged. Checked in Task 6, Step 5 (`config:show` comparison).

---

## File map

**`~/Sites/docker-edge/` (new, its own repo):**
- `compose.yaml`: traefik service, `edge` network.
- `dynamic/tls.yaml`: certificate list.
- `certs/`: mkcert output (git-ignored).
- `mac/local.loopback-alias.plist`, `mac/local.edge-forward-80.plist`, `mac/local.edge-forward-443.plist`: launchd daemons, kept as the source of what is installed in `/Library/LaunchDaemons`.
- `.gitignore`, `README.md`.

**tenancey:**
- Modify `bootstrap/app.php`: `trustProxies`.
- Modify `app/Http/Requests/StoreTenantRequest.php`: reserved names.
- Create `tests/Feature/TrustedProxyTest.php`.
- Modify `tests/Feature/Central/TenantFormValidationTest.php`, `tests/TestCase.php`, `tests/Feature/Provisioning/ProvisioningPipelineTest.php`, `tests/Feature/Central/ImpersonationTest.php`, `phpunit.xml`.
- Modify `compose.yaml`: edge network, labels, ports, env; later the env block moves out.
- Modify `.env`, `.env.example` (Task 6).
- Modify `vite.config.js`, `config/app.php`, `app/Providers/AppServiceProvider.php`, `composer.json`, `.gitignore`. Delete `tests/Feature/AssetBuildDirectoryTest.php` and `public/build-docker` (Task 6).
- Docs (Task 7): `docs/DOCKER.md`, `CLAUDE.md`, `README.md`, `docs/STORAGE.md`, `docs/BROADCASTING.md`, `docs/QUEUES.md`, `docs/ROUTING.md`, `deploy/supervisor/README.md`.

**Mac only (not in any repo):** `~/.config/valet/dnsmasq.d/tenancey.conf` (Task 5).

---

### Task 1: The socat forwarders (Mac layer)

**Files:**
- Create: `~/Sites/docker-edge/mac/local.loopback-alias.plist`, `~/Sites/docker-edge/mac/local.edge-forward-80.plist`, `~/Sites/docker-edge/mac/local.edge-forward-443.plist`
- Create: `docs/docker-learning/https-3-forwarders.md`

**Interfaces:**
- Produces: listeners on `127.0.0.2:80` → `127.0.0.2:18080` and `127.0.0.2:443` → `127.0.0.2:18443`, running at boot.

- [ ] **Step 1: Write the daemon files**

`~/Sites/docker-edge/mac/local.loopback-alias.plist`: a copy of the installed file, for the record:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
  <dict>
    <key>Label</key>
    <string>local.loopback-alias</string>
    <key>RunAtLoad</key>
    <true/>
    <key>ProgramArguments</key>
    <array>
      <string>/sbin/ifconfig</string>
      <string>lo0</string>
      <string>alias</string>
      <string>127.0.0.2</string>
    </array>
  </dict>
</plist>
```

`~/Sites/docker-edge/mac/local.edge-forward-443.plist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
  <dict>
    <key>Label</key>
    <string>local.edge-forward-443</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>ProgramArguments</key>
    <array>
      <string>/usr/local/bin/socat</string>
      <string>TCP-LISTEN:443,bind=127.0.0.2,reuseaddr,fork</string>
      <string>TCP:127.0.0.2:18443</string>
    </array>
    <key>StandardErrorPath</key>
    <string>/var/log/local.edge-forward-443.log</string>
  </dict>
</plist>
```

`~/Sites/docker-edge/mac/local.edge-forward-80.plist`: identical, with `443` → `80`, `18443` → `18080`, and the label and log name `local.edge-forward-80`.

- [ ] **Step 2: Lint (Claude)**

Run: `plutil -lint ~/Sites/docker-edge/mac/*.plist`
Expected: three `OK` lines.

- [ ] **Step 3: Install and load (user)**

```bash
cd ~/Sites/docker-edge/mac
for p in 80 443; do
  sudo install -o root -g wheel -m 644 local.edge-forward-$p.plist /Library/LaunchDaemons/
  sudo launchctl bootstrap system /Library/LaunchDaemons/local.edge-forward-$p.plist
done
```

- [ ] **Step 4: Verify (Claude, read-only)**

Run: `netstat -an -p tcp | grep LISTEN | grep -E '127\.0\.0\.2\.(80|443) '`
Expected: two lines, `127.0.0.2.443` and `127.0.0.2.80`.

Run: `curl -sS --max-time 3 http://127.0.0.2/ ; echo "exit=$?"`
Expected: an empty reply or reset (`exit=52` or `56`). socat accepts the connection, then the forward to `18080` is refused because nothing is there yet.

Run: `curl -sS --max-time 3 -o /dev/null -w '%{http_code}\n' http://127.0.0.1/`
Expected: `404` (Valet, unchanged).

- [ ] **Step 5: Learning note**

Write `docs/docker-learning/https-3-forwarders.md`:
- Why socat (link HTTPS 2).
- `KeepAlive` and the boot order (alias not ready → socat exits → launchd retries).
- `StandardErrorPath`.
- The commands above, and undo: `sudo launchctl bootout system/local.edge-forward-80`, then the same for 443, then `sudo rm` the two files.

Add the row to the README table.

---

### Task 2: mkcert and the docker-edge project

**Files:**
- Create: `~/Sites/docker-edge/compose.yaml`, `dynamic/tls.yaml`, `.gitignore`, `README.md`
- Create: `docs/docker-learning/https-4-docker-edge.md`

**Interfaces:**
- Consumes: Task 1's forwarders.
- Produces:
  - The external Docker network **`edge`**.
  - Entrypoint **`websecure`** (TLS) and entrypoint `web` (redirects to https).
  - The certificate files `/certs/tenancey.test.pem` and `/certs/tenancey.test-key.pem` inside traefik.
  - The dashboard at `http://127.0.0.2:18090`.

- [ ] **Step 1: Install mkcert (user)**

```bash
brew install mkcert
mkcert -install          # adds the local CA to the macOS keychain (asks for the password)
mkcert -CAROOT           # where the CA lives
```

- [ ] **Step 2: Write `~/Sites/docker-edge/compose.yaml`**

```yaml
# Shared HTTPS front door for every Docker project on this Mac. Guide: README.md
# The browser reaches 127.0.0.2:80/443; root socat daemons (mac/) forward them to 18080/18443 here.
name: docker-edge

services:
  traefik:
    image: traefik:v3.7.14@sha256:575fa15b135078fe5e50aa847987d96dbddd7b093c172429618404df73f3fa7c
    command:
      - --entrypoints.web.address=:80
      - --entrypoints.web.http.redirections.entrypoint.to=websecure
      - --entrypoints.web.http.redirections.entrypoint.scheme=https
      - --entrypoints.websecure.address=:443
      - --entrypoints.websecure.http.tls=true
      - --providers.docker=true
      - --providers.docker.exposedbydefault=false # only containers labelled traefik.enable=true
      - --providers.docker.network=edge
      - --providers.file.directory=/etc/traefik/dynamic
      - --providers.file.watch=true
      - --api.insecure=true # dashboard on :8080, published only on 127.0.0.2
      - --accesslog=true
      - --log.level=INFO
    ports:
      - "127.0.0.2:18080:80"
      - "127.0.0.2:18443:443"
      - "127.0.0.2:18090:8080"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./dynamic:/etc/traefik/dynamic:ro
      - ./certs:/certs:ro
    networks:
      - edge
    restart: unless-stopped

networks:
  edge:
    name: edge # fixed name: projects join it with `external: true`
```

- [ ] **Step 3: Write `dynamic/tls.yaml`, `.gitignore`**

`~/Sites/docker-edge/dynamic/tls.yaml`:
```yaml
# One entry per project. Traefik picks the certificate by host name (SNI). Watched: no restart needed.
tls:
  certificates:
    - certFile: /certs/tenancey.test.pem
      keyFile: /certs/tenancey.test-key.pem
```

`~/Sites/docker-edge/.gitignore`:
```
/certs/
```

- [ ] **Step 4: Validate the Compose file (Claude)**

Run: `cd ~/Sites/docker-edge && docker compose config --quiet && echo valid`
Expected: `valid`.

- [ ] **Step 5: Make the certificate and start (user)**

```bash
cd ~/Sites/docker-edge
mkdir -p certs
mkcert -cert-file certs/tenancey.test.pem -key-file certs/tenancey.test-key.pem tenancey.test "*.tenancey.test"
docker compose up -d
docker compose logs --tail=20 traefik
```
Expected in the log: no `level=error` lines about the certificate files or the Docker provider.

- [ ] **Step 6: Verify (Claude)**

Run: `docker network ls --filter name=^edge$ --format '{{.Name}}'`
Expected: `edge`.

Run: `curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.2:18090/api/rawdata`
Expected: `200` (dashboard API).

Run: `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://127.0.0.2/`
Expected: `301 https://127.0.0.2/` or `308 https://127.0.0.2/`: socat → Traefik `web` → redirect.

Run: `curl -sS -k -o /dev/null -w '%{http_code}\n' https://127.0.0.2/`
Expected: `404` (no router claims the host yet).

- [ ] **Step 7: Write `~/Sites/docker-edge/README.md`**

Sections:
- **What this is:** the table from spec section 3, plus the folder layout.
- **First-time setup:** the alias, the forwarders, mkcert, `docker compose up -d`.
- **Adding a project:**
  - Join `edge` as `external: true`.
  - Labels:
    - Router names prefixed with the project name.
    - `traefik.docker.network=edge`.
    - Explicit `priority` when a wildcard rule exists.
    - In Compose, `$` is written `$$`.
  - mkcert command, plus an entry in `dynamic/tls.yaml`.
  - A dnsmasq line `address=/<project>.test/127.0.0.2`, then `sudo brew services restart dnsmasq`.
  - `valet unlink <project>`.
- **Rules:**
  - Start docker-edge before any project.
  - Only browser-facing containers join `edge`, never PHP, database or cache containers. Service names on `edge` are visible to every project there, so a second `app` on `edge` would be found by another project's nginx.
- **Daily commands:** `docker compose ps`, `docker compose logs -f traefik`, the dashboard URL.
- **Troubleshooting:**
  - "network edge declared as external, but could not be found": run docker-edge's `up -d` first.
  - A browser certificate warning: run `mkcert -install` again.
  - A 404 from Traefik: check the router in the dashboard.
  - If file watch misses a change: `docker compose restart traefik`.
- **Undo everything:** daemons, the dnsmasq lines, `docker compose down`, `docker network rm edge`.

- [ ] **Step 8: Hand-off (user)**

```bash
cd ~/Sites/docker-edge && git init && git add . && git commit -m "Shared Traefik edge proxy on 127.0.0.2"
```

- [ ] **Step 9: Learning note**

Write `docs/docker-learning/https-4-docker-edge.md`. Cover:
- Reverse proxy and TLS termination.
- Entrypoints, routers and services.
- The Docker provider and labels, the file provider and SNI.
- An external network with a fixed name.
- mkcert's CA vs a certificate.
- Every command from this task.

Add the row to the README table.

---

### Task 3: Laravel behind an HTTPS proxy (code and tests)

Runs on the current Docker setup (`docker compose exec app …`). Valet is unaffected.

**Files:**
- Create: `tests/Feature/TrustedProxyTest.php`
- Modify: `bootstrap/app.php` (`withMiddleware` closure)
- Modify: `app/Http/Requests/StoreTenantRequest.php:21-23`
- Modify: `tests/Feature/Central/TenantFormValidationTest.php:33-34`
- Modify: `tests/TestCase.php:93-101`, `phpunit.xml:21-24`, `tests/Feature/Provisioning/ProvisioningPipelineTest.php:52`, `tests/Feature/Central/ImpersonationTest.php:34`

**Interfaces:**
- Produces: `StoreTenantRequest::RESERVED_SUBDOMAINS` includes `'s3', 'minio', 'ws'`. `TestCase::centralUrl()` and `TestCase::tenantUrl()` return `https://` URLs.

- [ ] **Step 1: Write the failing proxy test**

`tests/Feature/TrustedProxyTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    /**
     * Traefik ends HTTPS and forwards plain http to nginx with X-Forwarded-Proto: https.
     * Laravel must believe that header, or every URL it builds during the request is http://.
     */
    public function test_a_request_forwarded_as_https_builds_https_urls(): void
    {
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://tenancey.test/tenants')
            ->assertRedirect('https://tenancey.test/login');
    }

    public function test_a_plain_http_request_without_the_header_stays_http(): void
    {
        $this->get('http://tenancey.test/tenants')
            ->assertRedirect('http://tenancey.test/login');
    }
}
```

- [ ] **Step 2: Run it, watch the first test fail (user)**

Run: `docker compose exec app php artisan test --compact tests/Feature/TrustedProxyTest.php`
Expected: the first test FAILS (redirected to `http://tenancey.test/login`). The second passes.

- [ ] **Step 3: Trust the proxy**

`bootstrap/app.php`, at the top of the `withMiddleware` closure:
```php
    ->withMiddleware(function (Middleware $middleware): void {
        // Traefik (docker-edge) ends HTTPS and forwards http with X-Forwarded-Proto/Host/Port.
        // Only Traefik can reach nginx, so trusting any proxy is safe locally; production lists
        // its proxy's address instead.
        $middleware->trustProxies(at: '*');

        // Guests and signed-in users are redirected within their own world: tenant routes run
```

- [ ] **Step 4: Run it, both pass (user)**

Run: `docker compose exec app php artisan test --compact tests/Feature/TrustedProxyTest.php`
Expected: 2 passed.

- [ ] **Step 5: Write the failing reserved-name test**

`tests/Feature/Central/TenantFormValidationTest.php`: add after `'reserved horizon' => ['horizon'],`:
```php
            'reserved minio' => ['minio'],
```
(`s3` and `ws` are already refused by `min:3`. They still go in the constant, so the list holds even if the length rule changes.)

- [ ] **Step 6: Run it, watch it fail (user)**

Run: `docker compose exec app php artisan test --compact --filter='reserved minio' tests/Feature/Central/TenantFormValidationTest.php`
Expected: FAIL (no session error for `subdomain`).

- [ ] **Step 7: Reserve the names**

`app/Http/Requests/StoreTenantRequest.php`:
```php
    public const RESERVED_SUBDOMAINS = [
        'www', 'admin', 'api', 'mail', 'app', 'central', 'tenancey', 'static', 'assets', 'horizon',
        's3', 'minio', 'ws', // side services routed by Traefik (docs/DOCKER.md)
    ];
```

- [ ] **Step 8: Run it, passes (user)**

Same command as Step 6. Expected: PASS.

- [ ] **Step 9: Tests use https like the app**

`phpunit.xml`:
```xml
        <server name="APP_URL" value="https://tenancey.test"/>
```
`tests/TestCase.php`: in `tenantUrl()` and `centralUrl()`, replace `'http://'` with `'https://'`.
`tests/Feature/Provisioning/ProvisioningPipelineTest.php:52`: `'https://acme.tenancey.test/login'`.
`tests/Feature/Central/ImpersonationTest.php:34`: `'#^https://acme\.tenancey\.test/impersonate/[A-Za-z0-9]{64}$#'`.
`TestCase` comment above the helpers (if one exists): say https.

- [ ] **Step 10: Full suite (user)**

Run: `docker compose exec app php artisan test --compact`
Expected: all pass, except the existing skip in `PublicEndpointSigningTest`. A failure that names `http://` is another hard-coded URL: change it to `https://` and ledger it.

- [ ] **Step 11: Pint (user)**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Expected: clean or fixed.

- [ ] **Step 12: Hand-off (user)**

```bash
git checkout -b docker-only-https   # if not on it yet
git add bootstrap/app.php app/Http/Requests/StoreTenantRequest.php tests phpunit.xml
git commit -m "Trust the HTTPS proxy, reserve s3/minio/ws, tests on https"
```

---

### Task 4: tenancey behind Traefik (before the DNS switch)

**Files:**
- Modify: `compose.yaml`
- Create: `docs/docker-learning/https-5-tenancey-behind-traefik.md`

**Interfaces:**
- Consumes: the `edge` network and entrypoint `websecure` (Task 2). `trustProxies` (Task 3).
- Produces:
  - Routers `tenancey`, `tenancey-ws`, `tenancey-s3`, `tenancey-minio`, `tenancey-mail`.
  - Docker's `APP_URL=https://tenancey.test`, `CENTRAL_DOMAIN=tenancey.test`.

- [ ] **Step 1: Check Docker's database for the new reserved names (user)**

```bash
docker compose exec mysql mysql -uroot -pdocker -N -e "SELECT id, subdomain, deleted_at FROM tenancey.tenants WHERE subdomain IN ('s3','minio','ws')"
```
Expected: no rows. If any row comes back, stop and report it.

- [ ] **Step 2: Edit `compose.yaml`**

1. **Header comment:**
   ```yaml
   # App: https://tenancey.test   Tenants: https://{subdomain}.tenancey.test (through docker-edge)
   ```
2. **`x-laravel-env`:** change and add only these values. Everything else stays.
   ```yaml
     APP_URL: https://tenancey.test
     CENTRAL_DOMAIN: tenancey.test
     SESSION_SECURE_COOKIE: "true"
     MINIO_PUBLIC_ENDPOINT: https://s3.tenancey.test # signed links: browser → Traefik → minio:9000
   ```
3. **`reverb`:** remove `ports:` and add:
   ```yaml
       networks: [default, edge]
       labels:
         traefik.enable: "true"
         traefik.docker.network: edge
         traefik.http.routers.tenancey-ws.rule: Host(`ws.tenancey.test`)
         traefik.http.routers.tenancey-ws.entrypoints: websecure
         traefik.http.routers.tenancey-ws.priority: "100"
         traefik.http.services.tenancey-ws.loadbalancer.server.port: "8080"
   ```
   The `x-php` anchor has no `networks:`, so the other PHP services stay on `default` only.
4. **`nginx`:** remove `ports:` and add:
   ```yaml
       networks: [default, edge]
       labels:
         traefik.enable: "true"
         traefik.docker.network: edge
         # The central domain and every tenant. Priority 1, so the named side services below win
         # (Traefik's default priority is the rule's length, and this rule is the longest).
         traefik.http.routers.tenancey.rule: 'Host(`tenancey.test`) || HostRegexp(`^[a-z0-9-]+\.tenancey\.test$$`)'
         traefik.http.routers.tenancey.entrypoints: websecure
         traefik.http.routers.tenancey.priority: "1"
         traefik.http.services.tenancey.loadbalancer.server.port: "80"
   ```
5. **`mailpit`:** remove `ports:` and add:
   ```yaml
       networks: [default, edge]
       labels:
         traefik.enable: "true"
         traefik.docker.network: edge
         traefik.http.routers.tenancey-mail.rule: Host(`mail.tenancey.test`)
         traefik.http.routers.tenancey-mail.entrypoints: websecure
         traefik.http.routers.tenancey-mail.priority: "100"
         traefik.http.services.tenancey-mail.loadbalancer.server.port: "8025"
   ```
6. **`minio`:** remove `ports:`. Add `MINIO_BROWSER_REDIRECT_URL: https://minio.tenancey.test` to its `environment:`, then add:
   ```yaml
       networks: [default, edge]
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
   ```
7. **`node` `environment:`:**
   ```yaml
         ASSET_BUILD_DIRECTORY: build-docker
         VITE_REVERB_HOST: ws.tenancey.test # browser → Traefik → reverb:8080
         VITE_REVERB_PORT: "443"
         VITE_REVERB_SCHEME: https
   ```
8. **Top-level, after `volumes:`:**
   ```yaml
   networks:
     edge:
       external: true # created by ~/Sites/docker-edge (start that first)
   ```

The `tls` setting comes from the entrypoint (`websecure.http.tls=true`), so the routers don't repeat it.

- [ ] **Step 3: Validate (Claude)**

Run: `docker compose config --quiet && echo valid`
Expected: `valid`.

Run: `docker compose config nginx | grep -F 'tenancey\.test$'`
Expected: the rule line ends with a single `$`, i.e. `tenancey\.test$` followed by a backtick.

Run: `docker compose config | grep -cE '^\s+published:'`
Expected: `1` (only MySQL's 3307).

- [ ] **Step 4: Rebuild the assets with the new Reverb address (user)**

Run: `docker compose run --rm node`
Expected: the build finishes into `public/build-docker`.

Then (Claude): `grep -l 'ws.tenancey.test' public/build-docker/assets/*.js`
Expected: one file.

- [ ] **Step 5: Apply (user)**

```bash
docker compose up -d
docker compose ps
```
Expected: nginx, reverb, minio and mailpit are recreated (new networks and labels). The four PHP services are recreated (new env). All `Up`.

- [ ] **Step 6: Traefik sees the routers (Claude)**

Run: `curl -s http://127.0.0.2:18090/api/http/routers | grep -o '"name":"tenancey[^"]*"' | sort`
Expected: five names: `tenancey-mail@docker`, `tenancey-minio@docker`, `tenancey-s3@docker`, `tenancey-ws@docker`, `tenancey@docker`.

- [ ] **Step 7: Each host reaches its own service, without DNS (Claude)**

Set `CA="$(mkcert -CAROOT)/rootCA.pem"` and use `--resolve <host>:443:127.0.0.2` on each:
```bash
CA="$(mkcert -CAROOT)/rootCA.pem"
for h in tenancey.test acme.tenancey.test ws.tenancey.test s3.tenancey.test minio.tenancey.test mail.tenancey.test; do
  printf '%-22s ' $h
  curl -sS --cacert "$CA" --resolve $h:443:127.0.0.2 -o /dev/null -w '%{http_code} %{redirect_url}\n' https://$h/
done
curl -sS --cacert "$CA" --resolve s3.tenancey.test:443:127.0.0.2 https://s3.tenancey.test/minio/health/live -o /dev/null -w 's3 health %{http_code}\n'
curl -sS --cacert "$CA" --resolve tenancey.test:443:127.0.0.2 https://tenancey.test/login | grep -o 'https://tenancey.test/login' | head -1
```
Expected:
- `tenancey.test` → `302 https://tenancey.test/tenants` (home redirect), or a 302 to `/login`.
- `acme.tenancey.test` → a Laravel response (`404` for an unknown tenant, or a redirect for an existing one). It must be **Laravel's** page, not Traefik's plain `404 page not found`. Check with `curl … https://acme.tenancey.test/ | head -c 200`.
- `ws.tenancey.test` → Reverb's answer to a plain GET (`404`). It must not be Laravel's HTML. Check the body: no `<!DOCTYPE html>`.
- `s3.tenancey.test` → `403` (MinIO XML `AccessDenied`). `s3 health 200`.
- `minio.tenancey.test` → `200` (dashboard HTML).
- `mail.tenancey.test` → `200` (Mailpit).
- The last line prints `https://tenancey.test/login`: the form action is https, so `trustProxies` works.
- No certificate errors anywhere.

- [ ] **Step 8: Full suite in the new container env (user)**

Run: `docker compose exec app php artisan test --compact`
Expected: all pass (same skip as before).

- [ ] **Step 9: Learning note and hand-off**

Write `docs/docker-learning/https-5-tenancey-behind-traefik.md`. Cover:
- Labels as configuration.
- Router priority.
- `$$` in Compose.
- Two networks per service.
- `curl --resolve` to test before DNS.
- The expected outputs above.

Add the row to the README table.

Hand-off:
```bash
git add compose.yaml && git commit -m "Route Docker through docker-edge: https on tenancey.test, side services on subdomains"
```

---

### Task 5: The switch (DNS, Valet unlink, supervisor) and the browser walkthrough

**Files:**
- Create (Mac): `~/.config/valet/dnsmasq.d/tenancey.conf`
- Create: `docs/docker-learning/https-6-switch.md`

**Interfaces:**
- Consumes: everything from Tasks 1–4.
- Produces: `tenancey.test` and `*.tenancey.test` resolve to `127.0.0.2`.

- [ ] **Step 1: Write the dnsmasq line (Claude)**

`~/.config/valet/dnsmasq.d/tenancey.conf`:
```
# tenancey runs in Docker behind docker-edge (127.0.0.2). More specific than Valet's .test rule.
address=/tenancey.test/127.0.0.2
```

- [ ] **Step 2: Stop Valet's copy and restart dnsmasq (user)**

```bash
supervisorctl stop tenancey-horizon tenancey-reverb   # only these two
valet unlink tenancey
sudo brew services restart dnsmasq
sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder
```

- [ ] **Step 3: DNS precedence (Claude)**

Run: `dig +short tenancey.test @127.0.0.1; dig +short acme.tenancey.test @127.0.0.1; dig +short ws.tenancey.test @127.0.0.1`
Expected: `127.0.0.2` three times.

Run: `dig +short some-other-site.test @127.0.0.1`
Expected: `127.0.0.1` (Valet's rule still applies elsewhere).

Run: `dscacheutil -q host -a name acme.tenancey.test`
Expected: `ip_address: 127.0.0.2` (the system resolver agrees).

If the first `dig` returns `127.0.0.1`, the specific rule lost. Stop and apply the spec's fallback (`/etc/resolver/tenancey.test`) as a ledgered ruling.

- [ ] **Step 4: Plain curl, no tricks (Claude)**

Run: `curl -sS --cacert "$(mkcert -CAROOT)/rootCA.pem" -o /dev/null -w '%{http_code} %{redirect_url}\n' https://tenancey.test/`
Expected: `302 https://tenancey.test/…`.

Run: `curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://tenancey.test/`
Expected: `301` or `308` → `https://tenancey.test/`.

Run: `supervisorctl status | grep tenancey`
Expected: both `STOPPED`. Other programs unchanged.

- [ ] **Step 5: Browser walkthrough (user)**

Each item must work. Report any that doesn't.
1. `https://tenancey.test`: padlock, no warning. Sign in as `admin@tenancey.test` / `password`.
2. Create tenant `docker2`. The progress page updates live. DevTools → Network → WS shows `wss://ws.tenancey.test/app/…` with status 101.
3. Open `https://docker2.tenancey.test` and sign in as the first user.
4. Profile → upload an avatar. The thumbnail shows. Its URL starts `https://s3.tenancey.test/tenancey-…?X-Amz-…` (no `SignatureDoesNotMatch`).
5. My files → upload, download, delete.
6. Any email (for example the welcome mail, if sent) appears at `https://mail.tenancey.test`.
7. Central → the tenant page → Impersonate → you land in the tenant with the red banner. End returns to central.
8. `https://tenancey.test/horizon` and `https://tenancey.test/log-viewer` load.
9. `https://minio.tenancey.test`: sign in (`minioadmin` / `minioadmin`). The `docker2` bucket is listed.
10. Another Valet site (any `*.test`) still loads.
11. Delete `docker2`. The teardown progress is live, and the bucket is gone in the MinIO dashboard.

- [ ] **Step 6: Learning note**

Write `docs/docker-learning/https-6-switch.md`. Cover:
- dnsmasq's most-specific match.
- The macOS resolver cache.
- What `valet unlink` does.
- Why supervisor is stopped, not removed.
- The walkthrough results.

Add the row to the README table.

---

### Task 6: Docker-only cleanup (one `.env`, one asset build, no Mac tooling)

**Files:**
- Modify: `compose.yaml` (remove `x-laravel-env`, `environment: *laravel-env`, and `node.environment`)
- Modify: `.env` (values), `.env.example`
- Modify: `vite.config.js`, `config/app.php:60-68`, `app/Providers/AppServiceProvider.php`, `composer.json` (scripts), `.gitignore:18-19`
- Delete: `tests/Feature/AssetBuildDirectoryTest.php`, `public/build-docker/`, `public/build-docker.hot` if present
- Create: `docs/docker-learning/https-7-docker-only.md`

**Interfaces:**
- Consumes: Task 4's values in `x-laravel-env`.
- Produces: `.env` is the only source of Laravel settings. Assets are in `public/build`.

- [ ] **Step 1: Record the current effective config (Claude, before any change)**

```bash
for k in app.url tenancy.central_domains database.connections.mysql.host database.connections.mysql.port database.connections.mysql.username database.redis.default.host mail.mailers.smtp.host mail.mailers.smtp.port broadcasting.connections.reverb.options.host broadcasting.connections.reverb.options.port reverb.servers.reverb.port filesystems.default filesystems.disks.minio.endpoint filesystems.disks.minio.public_endpoint session.secure; do
  printf '%s = ' $k; docker compose exec -T app php artisan config:show $k 2>/dev/null | tail -n +2 | tr -s ' ' | tr '\n' ' '; echo
done > "$SCRATCH/config-before.txt"
```
(`$SCRATCH` is the session scratchpad.) Keep the file for Step 5.

- [ ] **Step 2: Move the settings into `.env` and `.env.example`**

In `.env`, set (edit existing keys, add missing ones next to their group; never print or change secrets):

| Key | Value |
|---|---|
| `APP_URL` | `https://tenancey.test` |
| `CENTRAL_DOMAIN` | `tenancey.test` |
| `SESSION_SECURE_COOKIE` | `true` (new, after `SESSION_DOMAIN`) |
| `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` | `mysql` / `3306` / `root` / `docker` |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` |
| `MAIL_HOST` / `MAIL_PORT` | `mailpit` / `1025` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `reverb` / `8080` / `http` |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `0.0.0.0` / `8080` |
| `VITE_REVERB_HOST` / `VITE_REVERB_PORT` / `VITE_REVERB_SCHEME` | `ws.tenancey.test` / `443` / `https` |
| `FILESYSTEM_DISK` | `minio` |
| `MINIO_ENDPOINT` | `http://minio:9000` |
| `MINIO_PUBLIC_ENDPOINT` | `https://s3.tenancey.test` (new, after `MINIO_REGION`) |
| `MINIO_KEY` / `MINIO_SECRET` | `minioadmin` / `minioadmin` |

`.env.example`: the same keys and values. Secrets stay empty as today. Comments describe Docker: for example the MinIO block says PHP uses `minio:9000` and links are signed for `MINIO_PUBLIC_ENDPOINT`. Remove the Valet-specific comments (HELO port 2525, `127.0.0.1:19000`).

- [ ] **Step 3: Remove the env block from `compose.yaml`**

- Delete the `x-laravel-env` anchor and its comment.
- Delete `environment: *laravel-env` from `x-php`.
- Delete the whole `environment:` block of `node`.
- Header comment: `# Laravel settings live in .env (Docker values only).`
- Keep MySQL's `MYSQL_ROOT_PASSWORD`, MinIO's root user and `MINIO_BROWSER_REDIRECT_URL`.

Run (Claude): `docker compose config --quiet && echo valid; grep -c laravel-env compose.yaml`
Expected: `valid`, then `0`.

- [ ] **Step 4: Apply (user)**

```bash
docker compose up -d     # PHP services recreated: their env now comes only from .env
docker compose restart horizon
```

- [ ] **Step 5: Compare effective config (Claude)**

Run the Step 1 loop again into `$SCRATCH/config-after.txt`, then `diff "$SCRATCH/config-before.txt" "$SCRATCH/config-after.txt"`.
Expected: no differences. Any difference is a missed key: fix `.env` and repeat.

- [ ] **Step 6: Remove the second asset folder**

`vite.config.js`: delete the `buildDirectory` comment and constant, and the `buildDirectory,` and `hotFile:` lines in `laravel({...})`. The plugin defaults are `public/build` and `public/hot`.

`config/app.php`: delete the `asset_build_directory` entry and its comment block.

`app/Providers/AppServiceProvider.php`:
- Delete `DevCommands::node('hot', 'vite');` and its comment.
- Delete `$this->configureAssetBuildDirectory();` and the `configureAssetBuildDirectory()` method.
- Remove the now-unused imports `Illuminate\Foundation\DevCommands` and `Illuminate\Support\Facades\Vite` (check that `Vite` is not used elsewhere in the file first).

Delete `tests/Feature/AssetBuildDirectoryTest.php`.

`composer.json`: delete the `"dev"` script.

`.gitignore`: delete `/public/build-docker` and `/public/build-docker.hot`.

Then the user runs: `rm -rf public/build-docker public/build-docker.hot`.

Run (Claude): `grep -rn -E 'ASSET_BUILD|asset_build_directory|build-docker|configureAssetBuildDirectory|DevCommands' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=docker-learning --exclude-dir=superpowers .`
Expected: matches only in docs that Task 7 rewrites (`docs/DOCKER.md`, `CLAUDE.md`).

- [ ] **Step 7: Build into `public/build` (user)**

Run: `docker compose run --rm node`
Expected: build output in `public/build`.

Then (Claude): `grep -l 'ws.tenancey.test' public/build/assets/*.js && test ! -e public/hot && echo ok`
Expected: a file name, then `ok`. Vite read `VITE_REVERB_*` from `.env`, and no stale hot file is left.

- [ ] **Step 8: Suite and Pint (user)**

```bash
docker compose exec app php artisan test --compact
docker compose exec app vendor/bin/pint --dirty --format agent
```
Expected: all pass (one test file fewer). Pint is clean.

- [ ] **Step 9: Browser smoke (user)**

Repeat walkthrough items 1, 2 (live progress), 4 (avatar) and 7 (impersonation) from Task 5.

- [ ] **Step 10: Learning note and hand-off**

Write `docs/docker-learning/https-7-docker-only.md`. Cover:
- Why the env block existed (beating Valet's `.env`) and why it can go now.
- Comparing effective config before/after.
- What Docker replaced (supervisor → services, Valet → nginx and Traefik, Mac Node → the `node` container).

Add the row to the README table.

Hand-off:
```bash
git add -A compose.yaml .env.example vite.config.js config/app.php app/Providers/AppServiceProvider.php composer.json .gitignore tests
git commit -m "Docker-only: settings in .env, one asset build, remove Valet tooling"
```

---

### Task 7: Documentation

**Files:**
- Modify: `docs/DOCKER.md`, `CLAUDE.md`, `README.md`, `docs/STORAGE.md`, `docs/BROADCASTING.md`, `docs/QUEUES.md`, `docs/ROUTING.md`, `deploy/supervisor/README.md`

- [ ] **Step 1: Find every Valet and old-port reference (Claude)**

Run: `grep -rn -i -E 'valet|tenancey\.localhost|:8000|8082|19010|19011|8025|build-docker|supervisor|127\.0\.0\.1:19000|2525|8081|composer dev|npm run (hot|dev)' CLAUDE.md README.md docs/*.md deploy/supervisor/README.md`
Expected: the list of lines to rewrite. Work through each file.

- [ ] **Step 2: `docs/DOCKER.md` becomes the development guide**

Sections:
1. **Overview:** the request path, browser → `127.0.0.2:443` → socat → Traefik → nginx/reverb/minio/mailpit.
2. **One-time Mac setup:** the alias, the forwarders, mkcert, docker-edge, the dnsmasq line. Link docker-edge's README.
3. **URLs:** the six host names, plus MySQL `127.0.0.1:3307`.
4. **Services:** the existing table, updated. Ports column: only MySQL is published. Add a networks column.
5. **Settings:** `.env` only, with the key values from Task 6.
6. **Daily commands:** `up -d`, `exec app php artisan …`, `run --rm node [npm run watch]`, tests, Horizon restart.
7. **Troubleshooting:**
   - The 502 resolver note (keep).
   - edge not up.
   - Certificate warning.
   - A Traefik 404 (router missing).
   - DNS check with `dig`.
   - A signature error on `s3.` (`MINIO_PUBLIC_ENDPOINT`).
   - Live progress not updating (`VITE_REVERB_*`, rebuild assets).
8. **Valet data left on the Mac:** what exists and that it is unused. Deleting it is a separate, by-name choice.

Remove all side-by-side content.

- [ ] **Step 3: `CLAUDE.md`**

- **Environment section:** rewrite for Docker only.
  - URLs.
  - MySQL `127.0.0.1:3307`, user `root`, password `docker`, central DB `tenancey`.
  - Redis and Mailpit in Docker.
  - MinIO at `s3.`/`minio.`.
  - Horizon and Reverb as Compose services.
  - `docker compose exec app php artisan …` for every artisan command.
- **"Never run config:cache…" rule:** replace with: "Don't run `config:cache`, `route:cache` or `optimize` in development: cached config hides `.env` changes. If it happens, `php artisan optimize:clear`."
- **Commands block:** the Docker equivalents (`docker compose exec app php artisan …`, `docker compose restart horizon`, `docker compose run --rm node`).
- **Testing section:**
  - `APP_URL` pin is `https://tenancey.test`.
  - The `minio` group uses the Docker MinIO.
  - Drop the Valet comparisons.
- **Reference docs list:** the `DOCKER.md` line says "the development setup".
- Keep everything inside `<laravel-boost-guidelines>` untouched.

- [ ] **Step 4: The other docs**

`README.md`, `docs/STORAGE.md`, `docs/BROADCASTING.md`, `docs/QUEUES.md`, `docs/ROUTING.md`: replace Valet and old-port references with the Docker equivalents from Step 1's list.
- `docs/ROUTING.md`: mention that Traefik is in front and that `trustProxies` is set.
- `docs/BROADCASTING.md`: describe the browser → `wss://ws.tenancey.test` → Traefik → `reverb:8080` path.
- `docs/STORAGE.md`: links are signed for `https://s3.tenancey.test`.

`deploy/supervisor/README.md`: add at the top:
> **Unused since the Docker-only switch (2026-10-13).** Horizon and Reverb run as Compose services. These files are kept for reference only.

- [ ] **Step 5: Re-run the Step 1 grep (Claude)**

Expected: remaining matches are only intentional (the supervisor README's note, `DOCKER.md`'s "Valet data left on the Mac" section, and history mentions). Nothing should describe Valet as a way to run the app.

- [ ] **Step 6: Hand-off**

```bash
git add CLAUDE.md README.md docs deploy/supervisor/README.md
git commit -m "Docs: Docker-only development over https"
```
