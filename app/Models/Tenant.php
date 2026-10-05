<?php

namespace App\Models;

use App\Casts\TenantSecret;
use App\Enums\TenantState;
use App\Tenancy\TenantDatabaseConfig;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;

/**
 * A tenant row in the landlord database.
 *
 * `id` is the internal auto-increment key; `uuid` is stancl's tenant key (used to look tenants up,
 * in queue payloads, by tenants:* commands, and in resource names). One tenant has exactly one
 * subdomain, stored here, so there is no stancl `domains` table (see App\Tenancy\SubdomainTenantResolver).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $subdomain
 * @property bool $enabled
 * @property TenantState $state
 * @property string $db_name
 * @property string|null $db_username
 * @property string|null $db_password
 * @property bool $db_master_access
 * @property Carbon|null $db_user_created_at
 * @property string $storage_path
 * @property string $cache_prefix
 * @property array{name: string, email: string, phone: ?string, password: string, must_change_password?: bool}|null $pending_admin
 * @property string|null $current_step
 * @property string|null $last_error
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $deleted_at
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use SoftDeletes;

    /**
     * Soft deleting must not fire stancl's TenantDeleted (cpcsl-cms lesson: wired to a delete
     * pipeline it would drop the database). Only a real force delete counts as "deleted".
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'saving' => Events\SavingTenant::class,
        'saved' => Events\TenantSaved::class,
        'creating' => Events\CreatingTenant::class,
        'created' => Events\TenantCreated::class,
        'updating' => Events\UpdatingTenant::class,
        'updated' => Events\TenantUpdated::class,
        'deleting' => Events\DeletingTenant::class,
        'forceDeleted' => Events\TenantDeleted::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->uuid ??= (string) Str::uuid7();
        });
    }

    public function getTenantKeyName(): string
    {
        return 'uuid';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Stancl stores its internal keys (db_name, db_username, db_password) as `tenancy_db_*`.
     * An empty prefix makes it read our real `db_*` columns, so the columns are the single source
     * of truth for the tenant's connection.
     */
    public static function internalPrefix(): string
    {
        return '';
    }

    public function database(): DatabaseConfig
    {
        return new TenantDatabaseConfig($this);
    }

    /**
     * Real columns on the tenants table. Anything else is stored in the `data` JSON column.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'uuid',
            'name',
            'subdomain',
            'enabled',
            'state',
            'db_name',
            'db_username',
            'db_password',
            'db_master_access',
            'db_user_created_at',
            'storage_path',
            'cache_prefix',
            'pending_admin',
            'current_step',
            'last_error',
            'provisioned_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'state' => TenantState::class,
            'db_password' => TenantSecret::class,
            'db_master_access' => 'boolean',
            'db_user_created_at' => 'datetime',
            'pending_admin' => 'encrypted:array',
            'provisioned_at' => 'datetime',
        ];
    }

    /**
     * Open to its users: switched on by the admin and fully provisioned.
     */
    public function isAccessible(): bool
    {
        return $this->enabled && $this->state === TenantState::Ready;
    }

    /**
     * Uses its own database user instead of the master credentials from .env.
     */
    public function hasDedicatedDatabaseUser(): bool
    {
        return $this->db_username !== null;
    }

    /**
     * Logging context for everything done to this tenant (see the `tenancy` log channel).
     *
     * @return array{tenant_id: int, tenant_uuid: string, tenant_subdomain: string}
     */
    public function logContext(): array
    {
        return [
            'tenant_id' => $this->id,
            'tenant_uuid' => $this->uuid,
            'tenant_subdomain' => $this->subdomain,
        ];
    }

    /**
     * The tenant's own host, e.g. acme.tenancey.test.
     */
    public function host(): string
    {
        return $this->subdomain.'.'.config('tenancy.central_domains')[0];
    }

    /**
     * Absolute URL on the tenant's subdomain. Use this instead of url() in queued or central
     * code, where url() would point at the central domain.
     */
    public function url(string $path = '/'): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'http';

        return $scheme.'://'.$this->host().'/'.ltrim($path, '/');
    }

    /**
     * Record which provisioning or teardown step is running.
     */
    public function markStep(string $step): void
    {
        $this->forceFill(['current_step' => $step])->save();
    }
}
