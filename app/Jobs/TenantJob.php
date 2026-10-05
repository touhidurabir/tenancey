<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Tenancy\Database\DatabaseUserManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Throwable;

/**
 * One step of a tenant provisioning or teardown chain.
 *
 * Every step takes only the tenant id and reloads the row (a serialized model would be stale on a
 * retry), records itself as the tenant's current step, and must be safe to run more than once:
 * the Retry button re-runs the whole chain and finished steps have to skip through.
 */
abstract class TenantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $tenantId) {}

    /**
     * Label shown in the admin UI while this step runs.
     */
    abstract public static function label(): string;

    abstract protected function process(Tenant $tenant): void;

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Always leaves the worker in central context. Stancl v3 does not end tenancy when tenant
     * code throws (e.g. a failing tenants:seed), and a long-lived Horizon worker would otherwise
     * run its NEXT job against this tenant's database.
     *
     * Every step is logged to the `tenancy` channel (start, finish with duration, or failure with
     * the exception), tagged through Context with the tenant and step. The run id set by
     * TenantProvisioner/TenantTeardown travels with the chain the same way.
     */
    public function handle(): void
    {
        $tenant = Tenant::withTrashed()->find($this->tenantId);

        Context::add([...($tenant?->logContext() ?? ['tenant_id' => $this->tenantId]), 'tenancy_step' => static::label()]);

        $startedAt = hrtime(true);
        $this->log('info', 'Step started', ['attempt' => $this->attempts()]);

        try {
            $this->perform();

            $this->log('info', 'Step finished', ['duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000)]);
        } catch (Throwable $e) {
            $this->log('error', 'Step failed: '.$e->getMessage(), [
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'will_retry' => $this->attempts() < $this->tries,
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    protected function perform(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $tenant->markStep(static::label());

        $this->process($tenant);
    }

    /**
     * Write to the `tenancy` log channel (tenant, run and step come from Context).
     *
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel('tenancy')->log($level, '['.static::label().'] '.$message, $context);
    }

    /**
     * Record why a step had nothing to do (e.g. no dedicated database user).
     */
    protected function skip(string $reason): void
    {
        $this->log('info', 'Skipped: '.$reason);
    }

    /**
     * Creates and drops the tenant's database (never a MySQL user).
     */
    protected function databaseManager(Tenant $tenant): MySQLDatabaseManager
    {
        /** @var MySQLDatabaseManager */
        return $tenant->database()->manager();
    }

    /**
     * Creates and drops the tenant's dedicated database user (driver-specific, see
     * config `tenancy.database.user_managers`). Only for tenants that have one.
     */
    protected function databaseUserManager(Tenant $tenant): DatabaseUserManager
    {
        if (blank($tenant->db_username)) {
            throw new RuntimeException("Tenant [{$tenant->uuid}] has no dedicated database user.");
        }

        return app(DatabaseUserManager::class);
    }

    /**
     * Run a callback inside the tenant's context, always returning to central afterwards.
     * Stancl v3's $tenant->run() does not end tenancy if the callback throws.
     */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        tenancy()->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            tenancy()->end();
        }
    }
}
