# Docker-only, served over HTTPS: design

Date: 2026-10-13. Status: **draft, awaiting review**.

## 1. Goal and constraints

**Goal.** Make tenancey a Docker-only app, served at `https://tenancey.test` and
`https://{subdomain}.tenancey.test`, and drop Valet for this app. This is the foundation for moving
other projects from Valet to Docker one at a time, and later for production. As in the earlier
Docker phases, **learning is a main purpose**: the work goes in small parts, each explained and
checked by hand before the next.

**Decided:**
- **Clean URLs, no ports.** The browser uses ports 80/443 on a second loopback address,
  `127.0.0.2`. Valet keeps `127.0.0.1` and its other sites, untouched.
- **A shared proxy outside the repo.** Traefik runs in its own Compose project,
  `~/Sites/docker-edge`, and serves every Docker project. tenancey is the first one behind it.
- **Docker-only tenancey.** The Valet paths leave the repo (two asset folders, Mac-only dev
  tooling, Valet settings in `.env`, Valet docs). `deploy/supervisor/` stays, marked unused.
- **Side services on subdomains of `tenancey.test`**, routed by Traefik: `s3.` (MinIO API,
  signed links), `minio.` (MinIO dashboard), `ws.` (Reverb), `mail.` (Mailpit).
- **Assets are built by the `node` container only.** No Vite dev server (HMR) yet.

**Out of scope:** production deployment and its image, GitHub Actions, CLI tenant creation,
backups, Playwright, React, HMR, and cleaning up Valet's data (the Mac's MySQL databases and
MinIO buckets are left untouched).

**Machine:** Intel Mac, macOS 14.5, Docker Desktop 4.94 (Engine 29.8). Valet serves other sites
on `127.0.0.1:80`. Another project's Reverb listens on `*:8080`.

## 2. Findings that shaped the design (throwaway tests, 2026-10-13)

1. **`.local` is unsuitable.** macOS resolves it over mDNS (Bonjour). And the clash with Valet
   is address + port, not the name.
2. **Valet's nginx listens on `127.0.0.1` only**, so a second loopback address is free.
3. **Docker Desktop 4.89+ can't publish ports below 1024 on one address.** The privileged helper
   and the "Allow privileged port mapping" setting were removed. Asking for `127.0.0.2:80` makes
   it listen on `0.0.0.0:80`, which clashes with Valet: `bind: address already in use`. High
   ports on one address work (`127.0.0.2:8443` ✔).
4. **pf can't redirect the Mac's own loopback traffic** on macOS 14.5. `rdr` rules in an anchor
   under `com.apple/*` were evaluated (counters rose) but never matched, with and without
   `on lo0`.
5. **socat running as root works.** `socat TCP-LISTEN:443,bind=127.0.0.2,reuseaddr,fork
   TCP:127.0.0.2:8443` → curl reached a container through `127.0.0.2:80` and `:443`, and Valet
   still answered on `127.0.0.1:80`.

The step-by-step record is in the (git-excluded) learning notes, parts HTTPS 0–2.

## 3. The Mac layer (one-time, shared by every Docker project)

| Piece | Detail |
|---|---|
| Address `127.0.0.2` | `lo0` alias. Boot daemon `/Library/LaunchDaemons/local.loopback-alias.plist` (`RunAtLoad`). **Done.** |
| Forwarder 80 | Boot daemon `local.edge-forward-80`: `/usr/local/bin/socat TCP-LISTEN:80,bind=127.0.0.2,reuseaddr,fork TCP:127.0.0.2:18080`. `RunAtLoad` + `KeepAlive`. |
| Forwarder 443 | Boot daemon `local.edge-forward-443`: same, `443` → `18443`. |
| mkcert | `brew install mkcert`, `mkcert -install` once (local CA in the macOS keychain). Firefox would also need `nss`; not used. |
| DNS | `~/.config/valet/dnsmasq.d/tenancey.conf`: `address=/tenancey.test/127.0.0.2` (covers every `*.tenancey.test`). Added at the switch (section 7). |

- **Ports `18080`/`18443`** avoid `8080` (the other project's Reverb on `*:8080`) and Docker's own
  ports in use (`3307`, `19010`…).
- **Boot order:** if socat starts before the alias exists, the bind fails, socat exits, and
  `KeepAlive` restarts it (launchd waits about 10 s between tries) until it works.
- **With nothing behind them**, the forwarders just refuse connections. Safe to install first.
- **DNS precedence:** the more specific `tenancey.test` rule must beat Valet's `*.test`. Checked
  first at the switch with `dig +short acme.tenancey.test @127.0.0.1`. Fallback if it doesn't:
  `/etc/resolver/tenancey.test` pointing at a separate dnsmasq.
- **Undo:** `launchctl bootout` + delete the daemon files, delete the dnsmasq line, `valet link`.

## 4. The shared proxy: `~/Sites/docker-edge`

Its own folder and its own git repo (the user runs `git init` and commits).

```
docker-edge/
├── compose.yaml        # traefik + the shared `edge` network
├── dynamic/tls.yaml    # certificate list (file provider, watched)
├── certs/              # mkcert output, git-ignored
├── .gitignore
└── README.md           # adding a project, daily commands, undo
```

**`traefik` service:**
- **Image:** Traefik v3, pinned by version and digest (looked up when the plan is executed).
- **Published, on `127.0.0.2` only:**
  - `18080` → entrypoint `web` (`:80`), which only redirects to https (permanent).
  - `18443` → entrypoint `websecure` (`:443`), TLS.
  - `18090` → dashboard and API (`--api.insecure`), read-only, for learning.
- **Docker provider:** the socket is mounted read-only, with `exposedByDefault=false` (only
  containers labelled `traefik.enable=true`). The default network is `edge`.
- **File provider:** the `dynamic/` folder is watched. `tls.yaml` lists each project's
  certificate. Traefik picks one by SNI, and an unknown host gets its built-in self-signed
  certificate and a 404.
- **Access log** on. `restart: unless-stopped`.

**Network:** created here with the fixed name `edge` (`name: edge`). Projects declare it
`external: true`, so docker-edge must be up before any project (otherwise: "network edge
declared as external, but could not be found").

**Certificates:**
```bash
mkcert -cert-file certs/tenancey.test.pem -key-file certs/tenancey.test-key.pem tenancey.test "*.tenancey.test"
```
A wildcard covers one level only, which is all tenancey needs. Production would get a Let's
Encrypt wildcard via the DNS challenge (later phase).

**Socket access** gives Traefik read access to Docker's API. That's acceptable locally.
Production would put a socket proxy in between (later phase).

**Adding a project later:** join `edge`, add labels, make a certificate and list it in
`tls.yaml`, add one dnsmasq line, `valet unlink`. Nothing else on the Mac changes.

## 5. tenancey changes

### 5.1 `compose.yaml`

- **Removed published ports:** nginx `8000`, reverb `8082`, minio `19010`/`19011`, mailpit `8025`.
  MySQL stays on `127.0.0.1:3307` (TablePlus). Redis is unchanged.
- **The `x-laravel-env` block is removed.** `.env` becomes the single source of Laravel settings
  (section 5.2). Non-Laravel settings stay in `compose.yaml` (for example MinIO's root user).
- **Joining `edge`:** only `nginx`, `reverb`, `minio` and `mailpit`. Each also stays on the
  project's default network and gets `traefik.docker.network=edge`.

| Router | Rule | Service → port | Priority |
|---|---|---|---|
| `tenancey-ws` | ``Host(`ws.tenancey.test`)`` | reverb → 8080 | 100 |
| `tenancey-s3` | ``Host(`s3.tenancey.test`)`` | minio → 9000 | 100 |
| `tenancey-minio` | ``Host(`minio.tenancey.test`)`` | minio → 9001 | 100 |
| `tenancey-mail` | ``Host(`mail.tenancey.test`)`` | mailpit → 8025 | 100 |
| `tenancey` | ``Host(`tenancey.test`) \|\| HostRegexp(`^[a-z0-9-]+\.tenancey\.test$`)`` | nginx → 80 | 1 |

All routers use entrypoint `websecure` with `tls=true`. **The explicit priorities are required:**
Traefik's default priority is the rule's length, so the long tenant rule would otherwise take
`ws.`, `s3.` and the others.

- **MinIO dashboard:** may need `MINIO_BROWSER_REDIRECT_URL=https://minio.tenancey.test`. Checked
  during execution.
- **`node` service:** command `sh -c "npm ci && npm run dev"` into `public/build`. Its
  `environment:` block (`ASSET_BUILD_DIRECTORY`, `VITE_REVERB_*`) is removed. Vite reads
  `VITE_*` from `.env`. Watch mode: `docker compose run --rm node npm run watch`.

### 5.2 `.env` / `.env.example` (Docker values only)

| Setting | Value | Note |
|---|---|---|
| `APP_URL` | `https://tenancey.test` | `Tenant::url()` takes scheme and port from it |
| `CENTRAL_DOMAIN` | `tenancey.test` | was `tenancey.localhost` in Docker |
| `SESSION_SECURE_COOKIE` | `true` | cookies only over https |
| `DB_HOST` / `DB_PORT` | `mysql` / `3306` | inside the network |
| `REDIS_HOST` | `redis` | |
| `MAIL_HOST` / `MAIL_PORT` | `mailpit` / `1025` | |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `reverb` / `8080` / `http` | PHP → Reverb, inside the network |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `0.0.0.0` / `8080` | |
| `VITE_REVERB_HOST` / `_PORT` / `_SCHEME` | `ws.tenancey.test` / `443` / `https` | browser → Traefik → Reverb |
| `FILESYSTEM_DISK` | `minio` | new tenants |
| `MINIO_ENDPOINT` | `http://minio:9000` | PHP → MinIO |
| `MINIO_PUBLIC_ENDPOINT` | `https://s3.tenancey.test` | what links are signed for |

Every other existing value stays, including secrets (`APP_KEY`, `TENANT_DB_KEY`, `REVERB_APP_*`)
and the MySQL credentials Docker's database was created with. The exact list is compared against
the current `compose.yaml` and `.env` during execution, so nothing is lost when the block moves.

### 5.3 Laravel code

- **Trusted proxies.** `bootstrap/app.php`: `$middleware->trustProxies(at: '*')`. Traefik
  terminates TLS and sends `X-Forwarded-Proto: https`. nginx passes request headers through to
  PHP. Without trusting the proxy, URLs generated during a request would be `http://`, and
  signed links (`tenant.storage.local`, impersonation) would fail validation. `'*'` is fine
  locally because only Traefik reaches nginx. Production lists its proxy's address.
- **Reserved subdomains.** Add `s3`, `minio` and `ws` to `StoreTenantRequest::RESERVED_SUBDOMAINS`
  (`mail` is already there). Before the switch, Docker's database is checked for tenants
  (including soft-deleted ones) that already use them. If one does, it is reported before
  anything else happens.
- **One asset build.** Remove `ASSET_BUILD_DIRECTORY`: `vite.config.js` (build directory and hot
  file), `config/app.php` (`asset_build_directory`),
  `AppServiceProvider::configureAssetBuildDirectory()`, and its test. Delete `public/build-docker`.
- **Mac-only tooling.** Remove `DevCommands::node('hot', 'vite')` and the `composer dev` script.

## 6. Tests

- They run in the container: `docker compose exec app php artisan test`.
- `phpunit.xml`: pin `APP_URL` to `https://tenancey.test` (still a `<server>` entry).
  `CENTRAL_DOMAIN` stays `tenancey.test`. Other pins are unchanged.
- `TenantUrlTest`: expectations become `https://…`.
- **New tests:**
  - `s3`, `minio` and `ws` are refused as subdomains.
  - A request with `X-Forwarded-Proto: https` generates `https://` URLs.
  - A signed link generated under https validates on a request that arrives over the proxy (http
    to nginx, `X-Forwarded-Proto: https`).
- The `minio` group still runs against Docker's MinIO. `PublicEndpointSigningTest`'s host test
  stays skipped.

## 7. Order of the switch

Valet keeps serving `tenancey.test` until step 4. Each step ends with a check.

1. **Mac:** the socat daemons and mkcert. Check: `netstat` shows `127.0.0.2.80` and `.443`.
2. **docker-edge:** create it, make the certificate, `docker compose up -d`. Check: the dashboard
   at `http://127.0.0.2:18090`, and `curl -k https://127.0.0.2` → Traefik's 404.
3. **tenancey on a branch** (sections 5–6). Check without touching DNS:
   `curl --resolve tenancey.test:443:127.0.0.2 https://tenancey.test` → the central login page
   with a trusted certificate. The full test suite passes in Docker.
4. **Switch:** the dnsmasq line and a dnsmasq restart, `dig` to check precedence, `valet unlink
   tenancey`, and `supervisorctl stop tenancey-horizon tenancey-reverb` (only these two programs).
5. **Browser walkthrough:** central sign-in. Create a tenant and watch live progress (`wss`). Sign
   in to the tenant, upload an avatar (link on `s3.`), download a file. An email in Mailpit
   (`mail.`). Impersonation (signed link). `/horizon`, `/log-viewer`, the MinIO dashboard.
   Another Valet site still works.
6. **Docs.**

**Data:** Docker's existing database and MinIO data carry over (volumes). Tenants store only
their subdomain, so their URLs follow the new `CENTRAL_DOMAIN`. Valet's databases and buckets
stay on the Mac, unused.

## 8. Documentation

- **`docs/DOCKER.md`:** the main development guide. It covers the Mac layer, docker-edge, URLs,
  daily commands and troubleshooting (502 after recreate, edge not up, certificate not trusted,
  DNS).
- **`CLAUDE.md`:** the Environment section rewritten for Docker only, with the new URLs. The
  shared-`bootstrap/cache` reason goes. "Don't cache config/routes in development" stays as a
  short rule.
- **`README.md`, `docs/STORAGE.md`, `docs/BROADCASTING.md`, `docs/QUEUES.md`, `docs/ROUTING.md`:**
  remove Valet paths and old ports.
- **`deploy/supervisor/README.md`:** a note at the top: unused since the Docker-only switch, kept
  for reference.
- **docker-edge `README.md`:** what it is, adding a project, undo.
- **Learning notes:** parts `https-3…` in `docs/docker-learning/` (git-excluded), one per step
  of section 7.

## 9. Working rules

- Claude writes repo files, docker-edge files and learning notes. The user runs `docker`,
  `sudo`, `valet`, `supervisorctl` and every git command. Claude makes no commits.
- Never touch the other project's Reverb (`*:8080`) or supervisor programs.
- Never drop databases or users by pattern. On MinIO, only `tenanceytest-*` buckets may be
  deleted by pattern.
- Never run `config:cache`, `route:cache` or `optimize`.
- Repo files never name the private reference projects.
