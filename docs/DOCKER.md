# Docker (local development)

Tenancey can run fully in Docker, **side by side with Valet**. Both read the same project folder,
but each has its own database, Redis, mail catcher and built assets.

| | Valet | Docker |
|---|---|---|
| Central app | `http://tenancey.test` | `http://tenancey.localhost:8000` |
| A tenant | `http://acme.tenancey.test` | `http://acme.tenancey.localhost:8000` |
| MySQL | Mac, `127.0.0.1:3306` | container `mysql`, from the Mac at `127.0.0.1:3307` |
| Redis | Mac, `127.0.0.1:6379` | container `redis` (not published) |
| Mail | HELO, port 2525 | container `mailpit`, inbox at `http://localhost:8025` |
| Reverb | supervisor, port 8081 | container `reverb`, from the browser at `localhost:8082` |
| Built assets | `public/build` | `public/build-docker` |

Browsers resolve every `*.localhost` name to the machine itself, so the Docker URLs need no DNS
or `/etc/hosts` setup.

Design: `docs/superpowers/specs/2026-10-07-docker-local-dev-design.md`.

## The one rule

**Never run `php artisan config:cache` or `php artisan optimize`, in either setup.** The cache
file `bootstrap/cache/config.php` lives in the shared folder, so it would force one setup's hosts
and ports onto the other. If it happens, run `php artisan config:clear`.

## How the two setups share one folder

Anything stored in a file is shared, so three things needed care:

1. **Settings (`.env`).** Docker's hosts and ports are set in `compose.yaml` as container
   environment variables. Laravel never lets `.env` overwrite a variable that is already set,
   so inside Docker the `compose.yaml` values win and `.env` fills in the rest (keys and
   secrets). Valet reads `.env` alone, as before.
2. **Tenant links.** `Tenant::url()` takes the scheme **and port** from `APP_URL`, so Docker's
   links (login link, welcome mail, live updates) keep `:8000`. Valet's `APP_URL` has no port.
3. **Built assets.** The JS has the Reverb host and port baked in when it is built, and the two
   setups use different ports. `ASSET_BUILD_DIRECTORY` picks the folder under `public/`, and both
   Vite (`vite.config.js`) and Laravel (`config('app.asset_build_directory')`, applied in
   `AppServiceProvider`) read it.
   - Unset (Valet): `public/build`, hot file `public/hot`.
   - Docker sets `build-docker`: `public/build-docker`, hot file `public/build-docker.hot`.

To try a Docker-style build on the Mac: `ASSET_BUILD_DIRECTORY=build-docker npm run dev`
(then `rm -rf public/build-docker`).

## The PHP image (`docker/php/`)

One image, `tenancey-php:dev`, runs all four PHP services (`app`, `horizon`, `reverb`,
`scheduler`). It is `php:8.4-fpm` plus:
- extensions `pdo_mysql`, `pcntl` (bundled) and `redis` 6.3.0 (PECL); `posix` is built in;
- `git`, `unzip` and Composer 2;
- `docker/php/php.ini`, loaded last as `zz-tenancey.ini` (512M memory, errors shown).

It holds no code: the project is bind-mounted at `/var/www/html`. The build context is
`docker/php/`, so the project folder is never sent to the builder.

```bash
docker build -t tenancey-php:dev docker/php     # Compose builds it for you once the app service exists
docker run --rm -v "$PWD":/var/www/html tenancey-php:dev composer check-platform-reqs
```
Rebuild after changing anything in `docker/php/`.

## MySQL and Redis

| Service | Inside Docker | From the Mac | Data |
|---|---|---|---|
| `mysql` (8.4) | `mysql:3306` | `127.0.0.1:3307`, `root` / `docker` | volume `tenancey_mysql-data` |
| `redis` (7.4) | `redis:6379` | not published | volume `tenancey_redis-data` |

- On its first start (an empty volume), MySQL runs `docker/mysql/init/*.sql`, which creates
  `tenancey` and `tenancey_testing`.
- Containers reach each other by **service name** on the `tenancey_default` network. Inside a
  container, `127.0.0.1` is the container itself.
- `docker compose down` keeps the data. `docker compose down -v` deletes Docker's MySQL and Redis
  data (every Docker tenant); delete tenants in the UI first, as with `migrate:fresh` on Valet.

```bash
docker compose exec mysql mysql -uroot -pdocker -e 'SHOW DATABASES'
docker compose exec redis redis-cli ping
```

## The app and nginx

```
browser → Mac :8000 → nginx (:80) ─ static file in public/? serve it
                                  └ otherwise FastCGI → app:9000 (PHP-FPM) → Laravel
```
- `compose.yaml` keeps Docker's settings in the `x-laravel-env` block and the shared PHP service
  setup in `x-php` (YAML anchors). `app` is `x-php` with the image's default command, `php-fpm`.
- Those settings are container environment variables, so they win over `.env`. `.env` still
  supplies everything else, including the keys and secrets.
- nginx (`docker/nginx/default.conf`) answers every host name (`server_name _`); Laravel tells
  central and tenants apart by the Host header. It mounts only `public/`, read-only, at the same
  path as in `app`.
- The project is bind-mounted into `app`: code changes apply on the next request, no rebuild.

```bash
docker compose up -d --build                      # --build after changing docker/php/
docker compose exec app php artisan migrate --seed --no-interaction
docker compose exec app php artisan <anything>    # artisan always runs inside app
curl -i http://tenancey.localhost:8000/up
```

## Building assets (node)

Docker's CSS and JS are built by a one-off `node` container (Node 22.12, as in `.nvmrc`) into
`public/build-docker`, with `VITE_REVERB_PORT=8082` baked in for the browser.
- It has the `tools` profile, so `docker compose up` never starts it.
- A named volume covers `node_modules` inside it, so the container installs Linux packages there
  while the Mac's `node_modules` (macOS packages, for Valet's build) stays untouched.
- Nothing runs afterwards: nginx serves the built files, and Laravel reads the manifest.

```bash
docker compose run --rm node                  # npm ci + build: first time, or after package*.json changes
docker compose run --rm node npm run dev      # rebuild only, after CSS/JS/Blade class changes
docker volume rm tenancey_node-modules        # reset Docker's packages (refilled on the next run)
```

## Horizon and Mailpit

- `horizon` is the PHP image with `command: php artisan horizon`. It replaces the supervisor
  program: `restart: unless-stopped` brings it back after a crash or a Docker restart.
  - `stop_signal: SIGTERM` is required: the PHP image's default stop signal (SIGQUIT) is for
    PHP-FPM, and Horizon ignores it.
  - `stop_grace_period: 660s` gives running jobs time to finish (longest job timeout 600s),
    like supervisor's `stopwaitsecs`.
- **After changing job code, restart Horizon**: `docker compose restart horizon` (instead of
  `php artisan horizon:terminate`). Long-running workers keep the old code in memory.
- `mailpit` catches every mail the PHP services send (`mailpit:1025`); nothing leaves your
  machine. Read the inbox at http://localhost:8025.

```bash
docker compose logs -f horizon                # follow the queue workers (Ctrl+C stops following)
docker compose restart horizon                # after changing job code
# dashboard: http://tenancey.localhost:8000/horizon   mail: http://localhost:8025
```

## Reverb

One Reverb container, reached on two addresses:
```
Laravel (app, horizon) ── reverb:8080 ──────────────────────────▶ reverb container :8080
Browser (on the Mac)   ── localhost:8082 ── published 8082:8080 ─▶ reverb container :8080
```
- **Run time, for PHP:** `REVERB_HOST=reverb`, `REVERB_PORT=8080` (in `x-laravel-env`). Laravel
  sends events over the Compose network.
- **Build time, for the browser:** `VITE_REVERB_HOST=localhost`, `VITE_REVERB_PORT=8082` (in the
  `node` service), baked into Docker's JS. Change them, then rebuild with
  `docker compose run --rm node npm run dev`.
- The server binds `0.0.0.0:8080` inside its container (`REVERB_SERVER_*`). Port 8080 there is
  the container's own; it doesn't clash with anything on the Mac. Valet's Reverb keeps 8081.
- `stop_signal: SIGTERM`, as for Horizon.

```bash
docker compose restart reverb     # after changing Reverb-related code or .env values (replaces reverb:restart)
docker compose up -d              # after changing compose.yaml: recreates the changed containers
docker compose logs -f reverb
```

## The scheduler

`scheduler` runs `php artisan schedule:work`: a loop that starts `schedule:run` at the top of
every minute, which runs whatever is due (today: `model:prune` for impersonation tokens, daily
at 00:00 UTC). It replaces the cron entry a server would have; containers don't run cron.

```bash
docker compose exec scheduler php artisan schedule:list   # what's scheduled, and when it's next due
docker compose exec scheduler php artisan schedule:test   # run one task now
# no restart needed after editing routes/console.php: each minute's schedule:run is a new PHP process
```

## Running the tests

```bash
docker compose exec app php artisan test --compact
```
- `phpunit.xml` pins `APP_URL` and `CENTRAL_DOMAIN` to Valet's `tenancey.test` names with
  `<server>` entries, so link assertions are the same everywhere. Tests never make real HTTP
  requests, so the names don't have to be reachable from Docker.
- `<server>` writes `$_SERVER`, which Laravel reads first. That's why it wins over the
  container's environment, where `<env>` entries can't.
- Everything else comes from `phpunit.xml` as on Valet: the `tenancey_testing` database (created
  by `docker/mysql/init/`), the test prefixes, and Redis DBs 13–15, all in Docker's MySQL and
  Redis.
