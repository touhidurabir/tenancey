# Broadcasting: live provisioning and teardown with Reverb

How a tenant save on the server becomes a live update in the admin's browser, with no page
reload. For the queue the chain runs on, see [QUEUES.md](QUEUES.md). For the central and tenant
hosts, see [ROUTING.md](ROUTING.md).

## 1. The pieces

| Piece | What it is | Where |
|---|---|---|
| **Reverb** | A WebSocket server. It holds the browsers' open connections and forwards messages to them | `php artisan reverb:start` (port 8081 locally) |
| **Broadcaster** | Laravel's side: sends events to Reverb over HTTP | `BROADCAST_CONNECTION=reverb`, `config/broadcasting.php` |
| **Echo** | The browser's side: opens the WebSocket and subscribes to channels | `resources/js/echo.js` (laravel-echo + pusher-js) |
| **Channel** | A named stream. Private channels (`private-…`) need Laravel's permission to join | `routes/channels.php` |
| **Event** | A PHP class that says what to send, on which channels, under which name | `app/Events/Tenant*Updated.php` |

### Two sets of host and port settings

| Setting | Used by | Meaning |
|---|---|---|
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `reverb:start` | Where the Reverb process **binds** (listens) |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | Laravel's broadcaster | Where Laravel **sends** events |
| `VITE_REVERB_*` (copied from `REVERB_*`) | Echo in the browser | Where the browser **connects**. Built into the JS by Vite, so rebuild after changing them |

Locally all of these point at port 8081. Behind a proxy in production, the public `REVERB_*`
address (e.g. `wss://ws.example.com:443`) differs from the bind address.

## 2. The channels

| Channel | Event(s) | Listened to by |
|---|---|---|
| `private-tenants.{uuid}.provisioning` | `TenantProvisioningUpdated` | The tenant page while it shows provisioning |
| `private-tenants.{uuid}.teardown` | `TenantTeardownUpdated` | The tenant page while it shows teardown |
| `private-tenants` | **Both** events, for every tenant | The `/tenants` list |

Each event goes to **two** channels: its own per-tenant channel and the shared list channel.
Both events use the same name (`progress.updated`) and the same payload:
- `uuid`, `name`, `host`, `show_url`, `login_url`, `enabled`
- `state`, `state_description`, `badge_classes`
- `current_step`, `last_error`

The payload never carries credentials.

## 3. The big picture

There are two separate flows, and they only meet inside Reverb:

- **Subscribing** happens once per page and channel. The browser asks Laravel for permission to
  listen on a channel. This is when the channel route (`/broadcasting/auth`) is hit.
- **Publishing** happens on every tenant save that matters. Laravel tells Reverb "send this on
  channel X". Reverb forwards it to every browser listening on X.

Laravel never talks to the browser directly about events, and the channel route is **not** hit
per event.

```
 Browser ──(1) WebSocket──────────────▶ Reverb (:8081)
 Browser ──(2) POST /broadcasting/auth ▶ Laravel   "may I listen on private-tenants?"
 Browser ──(3) subscribe + signature ─▶ Reverb     (now listening)
                                ...later...
 Laravel ──(4) HTTP POST event ───────▶ Reverb     "send progress.updated on private-tenants"
 Reverb  ──(5) WebSocket message ─────▶ Browser    → JS updates the row
```

## 4. Part A: the `/tenants` list page starts listening

This happens before any tenant is created, when the admin opens `/tenants`.

**A1. The page loads JS.** `@vite('resources/js/app.js')` loads `echo.js`, then
`tenant-progress.js`, then `tenant-list.js`.

**A2. `echo.js` opens a WebSocket.** `new Echo({ broadcaster: 'reverb', key, wsHost, wsPort })`
makes pusher-js connect to `ws://localhost:8081/app/{REVERB_APP_KEY}`. Reverb accepts and gives
this connection a **socket id**, such as `1234.5678`.

**A3. `tenant-list.js` asks to subscribe.**
```js
window.Echo.private('tenants').listen('.progress.updated', update);
```
`.private('tenants')` means the channel `private-tenants`. Because the name starts with
`private-`, Reverb won't let the socket join until Laravel approves.

**A4. The browser asks Laravel for permission. This is where the channel route is hit.**
pusher-js sends:
```
POST http://tenancey.test/broadcasting/auth
  socket_id=1234.5678 & channel_name=private-tenants
  Cookie: the admin's session      X-CSRF-TOKEN: from <meta name="csrf-token">
```
The `csrf-token` meta tag is in `resources/views/components/layouts/base.blade.php`.

**A5. Laravel checks.**
- **The route:** `bootstrap/app.php` has `withRouting(channels: …)`, which registers
  `/broadcasting/auth` (with the `web` middleware) and loads `routes/channels.php`.
- **The handler:** `BroadcastController::authenticate()` calls `Broadcast::auth()`. That strips
  the `private-` prefix and finds the matching rule:
  ```php
  Broadcast::channel('tenants', fn (CentralUser $admin) => true, ['guards' => ['central']]);
  ```
- **The guard:** it calls `$request->user('central')`. If there's no admin, the response is
  **403** and the subscription fails. If there is one, the closure runs and returns `true`.
- **The signature:** Laravel replies
  `{"auth": "<app key>:<HMAC of socket_id + channel, signed with REVERB_APP_SECRET>"}`.

**A6. The browser joins the channel.** pusher-js sends that signature to Reverb over the
WebSocket. Reverb has the same secret, so it checks the signature and adds the socket to
`private-tenants`.

From now on this tab receives everything sent on `private-tenants`, and Laravel isn't involved
on the browser side again.

### Why the channels name the `central` guard

`/broadcasting/auth` has only the `web` middleware, not `auth:central`. Without the `guards`
option, Laravel would ask the **default guard** (`web`, tenant users). On the central host that
guard finds no user, so even a signed-in admin would get a 403. `['guards' => ['central']]` tells
the broadcaster to use `$request->user('central')` instead. The guard itself is defined in
`config/auth.php` (driver `session`, provider `central_users` → `App\Models\CentralUser`).

## 5. Part B: creating a tenant (the first event)

**B1. `TenantController::store()`.** The create form POSTs `/tenants`. `StoreTenantRequest`
validates it, then `$provisioner->provision($data)` is called.

**B2. `TenantProvisioner::provision()`.**
1. It generates database credentials if needed.
2. It opens a **database transaction**, builds `new Tenant([...])` with `state = Provisioning`,
   and calls `$tenant->save()`.

**B3. Eloquent's model events run.** `save()` on a new row fires, in this order:
1. **`creating`**: our `booted()` hook fills in `uuid` if it's empty. Stancl's VirtualColumn
   packs any extra attributes into `data`.
2. **`INSERT INTO tenants …`**.
3. **`created`**: our hook from `Tenant::booted()` runs:
   ```php
   static::created(fn (Tenant $tenant) => $tenant->broadcastProgress());
   ```

**B4. `Tenant::broadcastProgress()`.**
1. **Pick the event.** The state is Provisioning, not Deleting or Deleted, so it builds
   `new TenantProvisioningUpdated($this)`.
2. **Take a snapshot.** The constructor (in `TenantProgressUpdated`) copies uuid, name, host,
   state, `current_step` and so on into `$this->progress`. Later changes to the model can't
   alter it.
3. **Wait for the commit.** `$this->getConnection()->afterCommit(...)`: we're inside B2's
   transaction, so the callback is **parked** until the commit. Otherwise the list could link to
   a row that doesn't exist yet.

**B5. The transaction commits, and the parked callback runs:**
```php
rescue(fn () => event($event));
```
`rescue()` means that if Reverb is down, the error is logged and execution carries on.

**B6. `event()`: Laravel's event dispatcher.** There's no listener class to look for. The
dispatcher sees that the event implements a broadcast interface (`ShouldBroadcastNow`), so it
hands it to the **BroadcastManager**.
- With `ShouldBroadcast`, it would be put on a queue.
- With **`Now`**, it runs immediately, in this same PHP process (see section 9).

**B7. The broadcaster builds the message from three methods on the event:**

| Method | Returns |
|---|---|
| `broadcastOn()` | `private-tenants.{uuid}.provisioning` and `private-tenants` |
| `broadcastAs()` | `progress.updated` |
| `broadcastWith()` | the snapshot array |

**B8. Laravel sends one HTTP request to Reverb.** The `reverb` driver uses the Pusher HTTP
protocol:
```
POST http://localhost:8081/apps/{REVERB_APP_ID}/events      (REVERB_HOST / REVERB_PORT)
  { name: "progress.updated", channels: [...both...], data: {...} }   signed with the key and secret
```
You can watch this arrive in a `reverb:start --debug` terminal.

**B9. Reverb forwards the message.** For each channel listed, it finds the sockets subscribed
to it:
- The `/tenants` tab from Part A is on `private-tenants`, so it receives the message.
- Nobody is on `private-tenants.{uuid}.provisioning` yet, so that copy goes nowhere. Reverb
  doesn't store messages.

**B10. The browser handles it.** pusher-js receives
`{ event: "progress.updated", channel: "private-tenants", data }`. Echo matches it to
`.listen('.progress.updated', update)`. The leading dot means "this exact name", instead of
Echo prefixing it with `App\Events\`. It then calls `update(data)` in `tenant-list.js`:
1. It looks for `data-tenant-row="{uuid}"` and doesn't find it.
2. It copies the `<template data-tenant-row-template>` row, fills it in, and puts it at the top.

The new tenant appears in the list.

**B11. Back in `provision()`.** `dispatchChain()` pushes the job chain (`Bus::chain(STEPS)`)
onto Redis. The controller writes the audit log entry and **redirects** the admin to the tenant
page.

## 6. Part C: the tenant page starts listening

This is Part A again, for a different channel.

1. **The controller passes the tenant.** `GET /tenants/{tenant}` uses route-model binding
   (`Tenant::getRouteKeyName()` is `uuid`), so `TenantController::show()` receives the model and
   passes it to the view.
2. **The view writes the values.** `show.blade.php` works out
   `$tearingDown = in_array($tenant->state, [Deleting, Deleted])` and renders:
   ```blade
   <section data-tenant-progress
            data-uuid="{{ $tenant->uuid }}"
            data-channel="{{ $tearingDown ? 'teardown' : 'provisioning' }}">
   ```
3. **The JS reads them back.** In `tenant-progress.js`:
   ```js
   const { uuid, channel } = section.dataset; // data-uuid → dataset.uuid, data-channel → dataset.channel
   window.Echo.private(`tenants.${uuid}.${channel}`)
   ```
4. **Laravel checks again.** `/broadcasting/auth` is hit with
   `channel_name=private-tenants.{uuid}.provisioning`. It matches
   `Broadcast::channel('tenants.{uuid}.provisioning', ...)`, checks the `central` guard and signs
   the request. Reverb then adds this socket to that channel.

The backend builds the same name in `TenantProvisioningUpdated::broadcastOn()`
(`'tenants.'.$this->progress['uuid'].'.provisioning'`). Both sides build the same string from
the same uuid, so the event reaches the subscribed page.

The page was rendered from the database, so it already shows whatever steps finished before it
subscribed.

## 7. Part D: each provisioning step (repeated 8 times)

**D1. Horizon picks up a job**, e.g. `CreateTenantDatabase`, from the `provisioning` queue.

**D2. `TenantJob::handle()` calls `perform()`**, which calls
`$tenant->markStep(static::label())`. That runs
`forceFill(['current_step' => 'Create database'])->save()`.

**D3. Eloquent fires `updating`, runs the `UPDATE`, then fires `updated`.** Our hook checks what
changed:
```php
static::updated(function (Tenant $tenant) {
    if ($tenant->wasChanged(['state', 'current_step', 'last_error'])) {
        $tenant->broadcastProgress();
    }
});
```
`current_step` changed, so `broadcastProgress()` runs.

**D4. Same as B4–B9, with one difference.** There's no open transaction this time, so
`afterCommit` runs the callback **straight away**. One HTTP POST goes to Reverb, and Reverb
pushes the message to both channels. Now **both** tabs are listening:
- **The `/tenants` row:** the step text and the badge update.
- **The tenant page:** steps before "Create database" get ✓, "Create database" gets "…", and the
  rest stay "·".

**D5. The job does its work** (`process()`). The chain then moves to the next job, and D1–D4
repeat.

**Saves that also send an event:**
- **`MarkTenantReady`** changes `state` to Ready. The badge turns green on both pages.
- **`SendTenantCredentials`** sets `current_step = null`. That means the run has finished:
  - the tenant page reloads to show the final view (buttons, resources, error box);
  - the list clears the step text.
- **On failure**, `TenantProvisioner::recordFailure()` saves `last_error`. The event carries the
  error, and the tenant page reloads to show it.

## 8. Deletion is the same path with a different event

`TenantController::destroy()` calls `TenantTeardown::teardown()`, which saves
`state = Deleting`. The `updated` hook runs `broadcastProgress()`, which sees Deleting and
builds **`TenantTeardownUpdated`**. That goes to `private-tenants.{uuid}.teardown` and
`private-tenants`. The tenant page now renders with `data-channel="teardown"`, so it subscribes
to the teardown channel.

The last step, `SoftDeleteTenant`, saves `state = Deleted` and `current_step = null`. The tenant
page reloads, and the list greys the row and turns its host into plain text.

## 9. Why `ShouldBroadcastNow`, not `ShouldBroadcast`

- **`ShouldBroadcast`**: `event()` puts a `BroadcastEvent` job on a queue. Later a worker picks
  it up and makes the HTTP call to Reverb.
- **`ShouldBroadcastNow`**: `event()` makes that HTTP call to Reverb immediately, in whatever
  process saved the tenant.

"Now" fits here because:

1. **Almost every event already comes from a queue job.** A queued broadcast would be a job
   dispatched from a job: it waits for a worker before anything reaches the browser.
2. **Order matters, and a queue doesn't guarantee it.** The JS treats each event as the latest
   truth ("the running step is X", "`current_step` is null, so reload"). With more than one
   worker process, queued broadcasts can be handled at the same time and arrive out of order.
   The page would reload early, or the list would jump back a step. With `Now`, each event is
   sent before the job moves on to the next step.
3. **Queued broadcasts would pick up tenancy.** The default queue connection (`redis`) is
   tenant-aware. A broadcast queued while a tenant was initialized would be tagged with it by
   `QueueTenancyBootstrapper`.

What we give up:
- **The process waits for Reverb.** That's fine in the chain jobs. Three events are fired during
  a web request (the new row in `provision()`, `retry()`, and `teardown()` setting Deleting),
  so there the admin's request waits too. On localhost that's negligible.
- **A Reverb outage loses events.** `rescue()` logs the error and the step carries on; there's
  no retry.
- **A hung Reverb slows the step**, up to the HTTP client's timeout.

Switch to `ShouldBroadcast` for broadcasts sent mainly from web requests, a remote or slow
broadcaster, or high volume. If you do, pin these events to the `central` connection
(`broadcastConnection()`, `broadcastQueue()`) and run that queue with a single worker so order
is kept.

## 10. Short version

| # | Where | What happens |
|---|---|---|
| 1 | Browser → Reverb | Open a WebSocket (once per page) |
| 2 | Browser → Laravel `/broadcasting/auth` | `routes/channels.php` + `central` guard → signed OK (once per channel) |
| 3 | Browser → Reverb | Join the channel using the signature |
| 4 | Laravel model `save()` | `created`/`updated` hook → `broadcastProgress()` → picks the event, takes the snapshot |
| 5 | Laravel `event()` | `ShouldBroadcastNow` → HTTP POST to Reverb with channels, name and data |
| 6 | Reverb → Browser | Pushes to the sockets on those channels |
| 7 | Browser JS | Echo `.listen('.progress.updated')` → `update()` redraws |

Steps 1–3 happen once per page and channel. Steps 4–7 happen on every relevant save.

## 11. Consequences to remember

- **Missed events are not replayed.** Reverb doesn't store messages. A page that subscribes late
  relies on its server render, and catches up on the next event or its end-of-run reload.
- **Only state, `current_step` and `last_error` broadcast** (plus the create). Renames and
  enable/disable don't. To make them live, add `name` and `enabled` to the `wasChanged([...])`
  list in `Tenant::booted()`.
- **After changing event or model code**, run `php artisan horizon:terminate`: the Horizon
  workers send most of these events and keep the old code until they restart.
- **After changing `VITE_REVERB_*` or the JS**, rebuild the assets (`npm run dev`).
- **Nothing updating?** The status line on the tenant page shows the WebSocket state:
  - `unavailable`: Reverb is not running (`php artisan reverb:start`).
  - `not authorized for this channel`: `/broadcasting/auth` returned 403. Are you signed in as
    a central admin?
  - Connected but no events: check Horizon (`supervisorctl status tenancey-horizon`), and
    `storage/logs/laravel.log` for broadcast errors caught by `rescue()`.
- **Tests run with `BROADCAST_CONNECTION=null`** (`phpunit.xml`), which authorizes nothing.
  `TenantProgressBroadcastTest` fakes the events, and switches to the Reverb driver only to
  check channel authorization (signing is local, so no server is needed).

## 12. Where everything lives

| What | Where |
|---|---|
| Reverb server and app keys | `.env` `REVERB_*`, `config/reverb.php` |
| Broadcaster (where Laravel sends) | `.env` `BROADCAST_CONNECTION`, `config/broadcasting.php` |
| `/broadcasting/auth` route | `bootstrap/app.php` → `withRouting(channels: …)` |
| Who may join which channel | `routes/channels.php` |
| The events | `app/Events/TenantProgressUpdated.php` (shared), `TenantProvisioningUpdated.php`, `TenantTeardownUpdated.php` |
| When an event fires | `App\Models\Tenant::booted()` (`created`/`updated`) → `broadcastProgress()` |
| Echo setup | `resources/js/echo.js` (`VITE_REVERB_*`) |
| CSRF token for the auth request | `resources/views/components/layouts/base.blade.php` |
| Tenant page | `resources/views/central/tenants/show.blade.php` + `resources/js/tenant-progress.js` |
| Tenants list | `resources/views/central/tenants/index.blade.php` + `resources/js/tenant-list.js` |
| Tests | `tests/Feature/Provisioning/TenantProgressBroadcastTest.php` |
