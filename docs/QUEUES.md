# Queues, connections and Horizon: what each word means

A reference for the terms that are easy to mix up in `config/queue.php`, `config/database.php`
and `config/horizon.php`. For running the Horizon process itself, see [deploy/supervisor/README.md](../deploy/supervisor/README.md).

## 1. Driver, queue connection, queue

| Term | What it is | Set in |
|---|---|---|
| **Driver** | The kind of backend, i.e. which storage code runs: `redis`, `database`, `sqs`, `sync`… | `'driver' => 'redis'` |
| **Queue connection** | A **named, configured instance of a driver**: the driver, where it stores jobs, the default queue name, timing rules and extra flags | a key under `queue.connections` |
| **Queue** | A named list of jobs inside that storage (`default`, `high`, `low`, `provisioning`…) | `->onQueue('x')`, or the connection's default `'queue'` |

A queue connection is to a driver what a DB connection is to `mysql`. One driver can have many
named connections, each set up differently.

### What a queue connection controls

```php
// config/queue.php
'redis'        => ['driver' => 'redis', 'connection' => 'queue', 'queue' => 'default',      'retry_after' => 150],
'central'      => ['driver' => 'redis', 'connection' => 'queue', 'queue' => 'central',      'retry_after' => 360, 'central' => true],
'provisioning' => ['driver' => 'redis', 'connection' => 'queue', 'queue' => 'provisioning', 'retry_after' => 900, 'central' => true],
```

All three use the same driver and the same Redis database. What differs:

- **`retry_after`**: if a job hasn't finished after this many seconds, the queue assumes its
  worker died and hands the job out again. It can **only be set per connection**, not per queue.
  Provisioning jobs can run for minutes, so they need their own connection with 900s. With 150s,
  a slow migration would run twice in parallel.
- **`central => true`**: read by stancl tenancy's queue bootstrapper.
  - `redis` (no flag) is **tenant-aware**: a job dispatched inside a tenant runs inside that
    tenant again.
  - `central` and `provisioning` never attach a tenant, so their jobs run in the landlord context.
- **Default queue**: used when the job doesn't call `onQueue()`.
- **`block_for`, `after_commit`**: how long a worker waits on Redis for a job; whether to
  dispatch only after the DB transaction commits.

**When you need which:**
- A new **queue** is enough for separate or prioritised lists with the same rules.
- A new **connection** is needed for different timing, a different server, or different
  tenancy behaviour.

### Using them

```php
Bus::chain($jobs)->onConnection('provisioning')->onQueue('provisioning')->dispatch();
```
```bash
php artisan queue:work provisioning --queue=provisioning
#                      ^ connection   ^ queues to read, in priority order
php artisan queue:work redis --queue=high,default,low      # priority: high is always emptied first
```

> **Trap: Redis keys come from the queue name only** (`queues:provisioning`), not the connection
> name. Two connections on the same Redis database that both use queue `default` share **one
> list**. A worker on either takes the other's jobs, with the wrong `retry_after` and the wrong
> tenancy behaviour. Keep queue names unique across connections, as they are here.

## 2. The `connection` key *inside* a queue connection

```php
'provisioning' => ['driver' => 'redis', 'connection' => 'queue', ...]
```

The same word, one level down. This `connection` names a **storage connection**, not a queue
connection:

| Driver | `'connection'` names… | Defined in |
|---|---|---|
| `redis` | a **Redis connection** | `config/database.php` → `redis` |
| `database` | a **DB connection** (`mysql`…) | `config/database.php` → `connections` |

### Redis connections in this project

```php
// config/database.php → 'redis'
'options' => ['prefix' => 'tenancey-database-'],      // global, applies to every connection below
'default' => [..., 'database' => env('REDIS_DB', '0')],        // sessions
'cache'   => [..., 'database' => env('REDIS_CACHE_DB', '1')],  // cache
'queue'   => [..., 'database' => env('REDIS_QUEUE_DB', '2')],  // queues + Horizon (horizon.use)
```

All three point at one Redis server (127.0.0.1:6379). `database` picks one of Redis's numbered
key spaces (0–15). So the full path of a provisioning job is:

```
onConnection('provisioning')    queue connection  (config/queue.php)
  driver     = redis            → the Redis queue code
  connection = 'queue'          → Redis connection (config/database.php): 127.0.0.1:6379, DB 2
  queue      = 'provisioning'   → the list name
        ↓
Redis DB 2, key  tenancey-database-queues:provisioning
```

### Why queues have their own, unprefixed Redis connection

`config/tenancy.php` → `redis.prefixed_connections` is `['default', 'cache']`. Stancl re-prefixes
those per tenant, so each tenant has its own sessions and cache keys. **`queue` is deliberately
left out:**

- **If it were prefixed**, a job dispatched in tenant acme would be written under acme's prefix,
  a list no worker reads.
- **Unprefixed**, every tenant's jobs land in the shared lists and one worker pool serves all
  tenants. The tenant id travels inside the job payload instead.

Being on its own Redis DB also means `cache:clear` (which flushes DB 1) never wipes queued jobs.

### The `database` queue connection (not used here)

```php
'database' => ['driver' => 'database', 'connection' => env('DB_QUEUE_CONNECTION'), ...]
```

`DB_QUEUE_CONNECTION` is not set, so `connection` is `null`. `null` means "the **current
default** DB connection", and under tenancy that default changes:

1. A request on `acme.tenancey.test` initializes tenancy, which **switches the default DB
   connection to `tenant`**.
2. A job dispatched there on the `database` queue goes into **acme's** database. That's a `jobs`
   table workers never poll, or a "table not found" error.

So if this connection is ever used, pin it to the landlord: `'connection' => 'mysql'` (or
`DB_QUEUE_CONNECTION=mysql`). This is the same reason central models use `CentralConnection`:
in tenant context, "default" no longer means landlord. Failed jobs and job batches are already
safe, because they name `DB_CONNECTION` (`mysql`) explicitly.

## 3. Horizon: master, supervisors, workers

The three entries in `config/horizon.php` → `defaults` are **not** workers. Each one defines a
**pool**: a group of identical workers, managed by one Horizon *supervisor* process. The live
process tree:

```
supervisord                                    the OS program (tenancey-horizon.conf). Not Laravel.
└─ artisan horizon                             Horizon master: exactly 1 per server
   ├─ horizon:supervisor …default-supervisor          pool manager (runs no jobs)
   │  └─ horizon:work redis        --queue=default       worker: runs jobs, one at a time
   ├─ horizon:supervisor …central-supervisor
   │  └─ horizon:work central      --queue=central
   └─ horizon:supervisor …provisioning-supervisor
      └─ horizon:work provisioning --queue=provisioning
```

| Level | How many | Job |
|---|---|---|
| **supervisord** | 1 program | Keeps `artisan horizon` alive; restarts it after a crash or `horizon:terminate` |
| **Horizon master** | 1 per server | Reads `config/horizon.php`, starts one supervisor per entry |
| **Horizon supervisor** | 1 per entry (3) | Starts, restarts and scales its workers, and recycles any over `memory` |
| **Worker** | `maxProcesses` per pool | Pops a job and runs it: same idea as `queue:work` |

"supervisord" and "Horizon supervisor" are unrelated things that happen to share a name.

### One pool definition

```php
'provisioning-supervisor' => [
    'connection'   => 'provisioning',    // queue connection its workers use (retry_after, central flag)
    'queue'        => ['provisioning'],  // lists to read; order = priority
    'balance'      => 'simple',          // fixed worker count ('auto' scales minProcesses..maxProcesses by load)
    'maxProcesses' => 1,                 // workers in this pool = jobs running in parallel
    'memory'       => 256,               // MB per worker before it is recycled
    'tries'        => 1,                 // default attempts; a job's own $tries wins
    'timeout'      => 600,               // kill a job running longer than this
],
```

**A pool has one connection but can read several queues.** That's why there are three pools: each
connection's rules differ.
- **Isolation**: a minutes-long provisioning chain can't block quick tenant jobs on `default`.
- **Own limits**: provisioning gets 600s and 256MB; the others get 120s/300s and 128MB.
- **Own scaling**: more `default` workers, but few provisioning workers so a burst of new
  tenants doesn't migrate many databases at once.

### `defaults` vs `environments`

`defaults` is the base. The block in `environments` matching `APP_ENV` is merged on top:

| | default | central | provisioning | total workers |
|---|---|---|---|---|
| `local` | 1 | 1 | 1 | 3 |
| `production` | 3 | 2 | 2 | 7 |

**An `APP_ENV` with no block in `environments` runs no supervisors at all.** For example, a
`staging` server would process nothing until a `staging` block is added.

## 4. Timing rules that must hold

```
Horizon pool timeout  <  queue connection retry_after  <  supervisord stopwaitsecs  (≥ longest timeout)
```

| Pool | `timeout` | `retry_after` |
|---|---|---|
| default | 120 | 150 |
| central | 300 | 360 |
| provisioning | 600 | 900 |

- **`timeout < retry_after`**: otherwise a still-running job is handed to a second worker and
  runs twice.
- **`stopwaitsecs` (660) > longest `timeout` (600)**: on stop or restart, Horizon lets running
  jobs finish. If supervisord stops waiting first, it SIGKILLs a tenant halfway through its chain.

Change any of these numbers together, never one alone.

## 5. Quick answers

- **"Which Redis DB are my jobs in?"** DB 2, via Redis connection `queue`, under
  `tenancey-database-queues:<queue>`. Check with `redis-cli -n 2 --scan --pattern 'tenancey-database-queues:*'`.
  - A list exists only while jobs are waiting, so it's normally empty.
  - Horizon keeps its own dashboard data (job metrics, supervisors, failed jobs) in the same DB
    under a separate prefix, `tenancey_horizon:` (`horizon.prefix`).
- **"I changed a job class and nothing changed."** Workers keep old code in memory. Run
  `php artisan horizon:terminate`; supervisord restarts Horizon on the new code.
- **"I need more parallel provisioning."** Raise `provisioning-supervisor.maxProcesses` for that
  environment. Every running job uses a worker and a DB connection.
- **"I want a new priority queue."** Add it to an existing pool's `queue` list (e.g.
  `['high', 'default']`). It only needs a new connection, and a new pool, if its timing or
  tenancy rules differ.
- **"Can I run `queue:work` too?"** No. It would consume the same Redis lists as Horizon. Horizon
  is the only worker process.
