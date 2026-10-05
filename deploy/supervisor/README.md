# Supervised processes

Confused by driver vs queue connection vs queue, or Horizon supervisors vs workers? See
[docs/QUEUES.md](../../docs/QUEUES.md).

| Program | What it does | If it dies |
|---|---|---|
| `tenancey-horizon` | Laravel Horizon. Runs every queue worker defined in `config/horizon.php`: `default`, `central` and `provisioning` | Tenants are never provisioned or deleted; the admin UI shows "Provisioning" forever, with no error |

- **Production:** `tenancey-horizon.conf` (`/var/www/tenancey`, user `www-data`).
- **Local (macOS, Homebrew supervisor):** `local/tenancey.ini`, the same program with local paths.
  Copy it to `/usr/local/etc/supervisor.d/` (only `*.ini` files are included there), then run
  `supervisorctl reread && supervisorctl update`.

Rules:
- **One Horizon per machine.** Don't also run `php artisan horizon` or `composer dev` (which
  starts Horizon) while the supervised one is running.
- **After changing code** (jobs especially), run `php artisan horizon:terminate`. Workers keep
  old code in memory; supervisor restarts Horizon on the new code.
- `stopwaitsecs` (660) must stay above the longest Horizon supervisor `timeout` (600, provisioning).
- Check it: `supervisorctl status tenancey-horizon`, `php artisan horizon:status`, or the dashboard
  at http://tenancey.test/horizon.
