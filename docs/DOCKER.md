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
