<?php

namespace App\Services;

use App\Enums\TenantState;
use App\Jobs\Teardown\DeleteTenantStorage;
use App\Jobs\Teardown\DropTenantDatabase;
use App\Jobs\Teardown\DropTenantDatabaseUser;
use App\Jobs\Teardown\FlushTenantCache;
use App\Jobs\Teardown\SoftDeleteTenant;
use App\Jobs\TenantJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deletes a tenant: marks it Deleting (which closes its subdomain immediately), then a queued
 * chain removes its database, MySQL user (if any), storage and Redis keys, and finally marks it
 * Deleted and soft deletes the row, which stays as the record.
 */
class TenantTeardown
{
    /**
     * The teardown chain, in order.
     *
     * @var list<class-string<TenantJob>>
     */
    public const STEPS = [
        DropTenantDatabase::class,
        DropTenantDatabaseUser::class,
        DeleteTenantStorage::class,
        FlushTenantCache::class,
        SoftDeleteTenant::class,
    ];

    /**
     * Start (or retry) the teardown.
     */
    public function teardown(Tenant $tenant): void
    {
        $tenant->forceFill(['state' => TenantState::Deleting, 'last_error' => null])->save();

        $tenantId = $tenant->id;

        Context::add([...$tenant->logContext(), 'tenancy_run' => (string) Str::ulid(), 'tenancy_operation' => 'teardown']);
        Log::channel('tenancy')->info('Teardown run started', [
            'db_name' => $tenant->db_name,
            'db_username' => $tenant->db_username,
            'storage_path' => $tenant->storage_path,
        ]);

        Bus::chain(array_map(fn (string $job) => new $job($tenantId), self::STEPS))
            ->onConnection('provisioning')
            ->onQueue('provisioning')
            ->catch(fn (Throwable $e) => TenantTeardown::recordFailure($tenantId, $e))
            ->dispatch();
    }

    /**
     * Called by the chain's catch(). The tenant stays Deleting so it remains closed.
     */
    public static function recordFailure(int $tenantId, Throwable $e): void
    {
        $tenant = Tenant::query()->find($tenantId);

        $tenant?->forceFill([
            'last_error' => '['.($tenant->current_step ?? 'unknown step').'] '.$e->getMessage(),
        ])->save();

        Log::channel('tenancy')->error('Teardown run failed at ['.($tenant?->current_step ?? 'unknown step').']', [
            'tenant_id' => $tenantId,
            'error' => $e->getMessage(),
        ]);
    }
}
