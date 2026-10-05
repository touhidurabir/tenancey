<?php

namespace App\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Keeps spatie/laravel-permission per tenant (pattern from cpcsl-cms).
 *
 * The registrar is a singleton that also memoizes the permission collection in process memory,
 * so in a long-lived worker the first tenant's roles/permissions would answer for every later
 * tenant. clearPermissionsCollection() drops that memo without deleting any cache entry
 * (forgetCachedPermissions() would also delete the cache, forcing a reload on every request).
 * The cache key is per tenant as well, so tenants never read each other's cached permissions
 * even when the cache store is not Redis-prefixed.
 */
class SpatiePermissionsBootstrapper implements TenancyBootstrapper
{
    public function __construct(protected PermissionRegistrar $registrar) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->registrar->cacheKey = config('permission.cache.key').'.tenant.'.$tenant->getTenantKey();
        $this->registrar->clearPermissionsCollection();
    }

    public function revert(): void
    {
        $this->registrar->cacheKey = config('permission.cache.key');
        $this->registrar->clearPermissionsCollection();
    }
}
