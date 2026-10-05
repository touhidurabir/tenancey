<?php

namespace App\Services;

use App\Enums\TenantState;
use App\Jobs\Provisioning\CreateFirstTenantUser;
use App\Jobs\Provisioning\CreateTenantDatabase;
use App\Jobs\Provisioning\CreateTenantDatabaseUser;
use App\Jobs\Provisioning\MarkTenantReady;
use App\Jobs\Provisioning\MigrateTenantDatabase;
use App\Jobs\Provisioning\PrepareTenantStorage;
use App\Jobs\Provisioning\SeedTenantDatabase;
use App\Jobs\Provisioning\SendTenantCredentials;
use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Tenancy\Database\DatabaseCredentials;
use App\Tenancy\Database\DatabaseUserManager;
use App\Tenancy\TenantResourceGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Creates a tenant: saves its landlord rows, then hands the real work to a queued chain of
 * single-purpose jobs on the `provisioning` connection (watch it in Horizon).
 */
class TenantProvisioner
{
    /**
     * The provisioning chain, in order.
     *
     * @var list<class-string<TenantJob>>
     */
    public const STEPS = [
        CreateTenantDatabase::class,
        CreateTenantDatabaseUser::class,
        MigrateTenantDatabase::class,
        SeedTenantDatabase::class,
        PrepareTenantStorage::class,
        CreateFirstTenantUser::class,
        MarkTenantReady::class,
        SendTenantCredentials::class,
    ];

    /**
     * @param  array{
     *     name: string,
     *     subdomain: string,
     *     admin_name: string,
     *     admin_email: string,
     *     admin_phone?: ?string,
     *     admin_password: string,
     *     admin_must_change_password?: bool,
     *     dedicated_db_user?: bool,
     *     db_username?: ?string,
     *     db_password?: ?string,
     *     db_master_access?: bool,
     * }  $data  without a dedicated database user the tenant connects with the master .env
     *           credentials. With one, an empty username or password is generated.
     */
    public function provision(array $data): Tenant
    {
        $dedicated = (bool) ($data['dedicated_db_user'] ?? false);

        // Generate before the transaction: it reads the database server's user list.
        $dbUsername = $dedicated ? (filled($data['db_username'] ?? null) ? $data['db_username'] : DatabaseCredentials::generateUsername(app(DatabaseUserManager::class))) : null;
        $dbPassword = $dedicated ? (filled($data['db_password'] ?? null) ? $data['db_password'] : DatabaseCredentials::generatePassword()) : null;

        // Commit the row BEFORE dispatching, so the first job always finds it.
        $tenant = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($data, $dedicated, $dbUsername, $dbPassword) {
            $tenant = new Tenant([
                'name' => $data['name'],
                'subdomain' => $data['subdomain'],
                'enabled' => true,
                'state' => TenantState::Provisioning,
                'pending_admin' => [
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'phone' => $data['admin_phone'] ?? null,
                    'password' => $data['admin_password'],
                    'must_change_password' => (bool) ($data['admin_must_change_password'] ?? true),
                ],
            ]);
            $tenant->uuid = (string) Str::uuid7();
            $tenant->db_name = TenantResourceGuard::databaseNameFor($tenant->uuid);
            $tenant->db_username = $dbUsername;
            $tenant->db_password = $dbPassword;
            // Without a dedicated user the master user IS the connection, so it has access.
            $tenant->db_master_access = $dedicated ? (bool) ($data['db_master_access'] ?? true) : true;
            $tenant->storage_path = TenantResourceGuard::storagePathFor($tenant->uuid);
            $tenant->cache_prefix = config('tenancy.database.prefix').$tenant->uuid.':';
            $tenant->save();

            return $tenant;
        });

        $this->dispatchChain($tenant, 'provision', [
            'dedicated_db_user' => $dedicated,
            'db_username' => $dbUsername,
            'db_username_generated' => $dedicated && blank($data['db_username'] ?? null),
            'db_password_generated' => $dedicated && blank($data['db_password'] ?? null),
            'db_master_access' => $tenant->db_master_access,
            'admin_email' => $data['admin_email'],
            'admin_must_change_password' => $tenant->pending_admin['must_change_password'],
        ]);

        return $tenant;
    }

    /**
     * Re-run the chain after a failure. Finished steps skip, so it resumes at the failed one.
     * Also serves "Resend credentials" for an active tenant whose email failed.
     */
    public function retry(Tenant $tenant): void
    {
        if (! $this->canRetry($tenant)) {
            throw new LogicException("Tenant [{$tenant->id}] has nothing to retry.");
        }

        $tenant->forceFill([
            // A failed tenant goes back to provisioning (its subdomain shows "being set up");
            // a ready one stays ready while its credentials are resent.
            'state' => $tenant->state === TenantState::Failed ? TenantState::Provisioning : $tenant->state,
            'last_error' => null,
        ])->save();

        $this->dispatchChain($tenant, 'retry');
    }

    public function canRetry(Tenant $tenant): bool
    {
        return $tenant->state === TenantState::Failed
            || ($tenant->state === TenantState::Ready && $tenant->pending_admin !== null);
    }

    /**
     * Called by the chain's catch() once a job has used up its retries.
     */
    public static function recordFailure(int $tenantId, Throwable $e): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return;
        }

        // A failure after activation (the credentials email) must not lock out a working tenant.
        if ($tenant->state === TenantState::Provisioning) {
            $tenant->state = TenantState::Failed;
        }

        $tenant->last_error = '['.($tenant->current_step ?? 'unknown step').'] '.$e->getMessage();
        $tenant->save();

        Log::channel('tenancy')->error('Provisioning run failed at ['.($tenant->current_step ?? 'unknown step').']', [
            ...$tenant->logContext(),
            'state' => $tenant->state->label(),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $details  logged with the run start (never secrets)
     */
    protected function dispatchChain(Tenant $tenant, string $operation, array $details = []): void
    {
        $tenantId = $tenant->id;

        // Tags every log line of this run, including in the queued jobs (Context travels with them).
        Context::add([...$tenant->logContext(), 'tenancy_run' => (string) Str::ulid(), 'tenancy_operation' => $operation]);
        Log::channel('tenancy')->info("Provisioning run started ({$operation})", $details);

        Bus::chain(array_map(fn (string $job) => new $job($tenantId), self::STEPS))
            ->onConnection('provisioning')
            ->onQueue('provisioning')
            ->catch(fn (Throwable $e) => TenantProvisioner::recordFailure($tenantId, $e))
            ->dispatch();
    }
}
