# Supervised processes

Confused by driver vs queue connection vs queue, or Horizon supervisors vs workers? See
[docs/QUEUES.md](../../docs/QUEUES.md).

| Program | What it does | If it dies |
|---|---|---|
| `tenancey-horizon` | Laravel Horizon. Runs every queue worker defined in `config/horizon.php`: `default`, `central` and `provisioning` | Tenants are never provisioned or deleted; the admin UI shows "Provisioning" forever, with no error |
| `tenancey-reverb` | Laravel Reverb, the WebSocket server for live progress on `/tenants` and the tenant page ([docs/BROADCASTING.md](../../docs/BROADCASTING.md)). Binds to `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` | Provisioning still works, but the pages stop updating live (status line: "unavailable"); broadcast errors go to `storage/logs/laravel.log` |

- **Production:** `tenancey-horizon.conf` and `tenancey-reverb.conf` (`/var/www/tenancey`, user `www-data`).
- **Local (macOS, Homebrew supervisor):** `local/tenancey.ini`, the same programs with local paths.
  Copy it to `/usr/local/etc/supervisor.d/` (only `*.ini` files are included there), then run
  `supervisorctl reread && supervisorctl update`.

Rules:
- **One Horizon per machine.** Don't also run `php artisan horizon` or `composer dev` (which
  starts Horizon) while the supervised one is running.
- **After changing code** (jobs especially), run `php artisan horizon:terminate`. Workers keep
  old code in memory; supervisor restarts Horizon on the new code.
- **One Reverb per app.** Don't also run `php artisan reverb:start` in a terminal: it fails with
  EADDRINUSE on the same port. For debug output, stop the supervised one first
  (`supervisorctl stop tenancey-reverb`), run `php artisan reverb:start --debug`, then start it again.
- **After changing code or `REVERB_*` settings**, run `php artisan reverb:restart` (graceful;
  supervisor starts it again). Changed `VITE_REVERB_*` also need `npm run dev`.
- `stopwaitsecs` (660) must stay above the longest Horizon supervisor `timeout` (600, provisioning).
- Check them: `supervisorctl status tenancey-horizon tenancey-reverb`, `php artisan horizon:status`, or the dashboard
  at http://tenancey.test/horizon.
