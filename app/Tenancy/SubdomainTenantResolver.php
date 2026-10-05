<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * Finds the tenant by `tenants.subdomain` instead of stancl's `domains` table: a tenant here
 * always has exactly one subdomain. Bound in place of DomainTenantResolver by
 * TenancyServiceProvider, so stancl's InitializeTenancyBySubdomain middleware uses it.
 *
 * Soft-deleted tenants are never found (the SoftDeletes scope), so their subdomains show
 * "No such workspace".
 */
class SubdomainTenantResolver extends DomainTenantResolver
{
    public function resolveWithoutCache(...$args): TenantContract
    {
        $tenant = Tenant::query()->where('subdomain', $args[0])->first();

        if ($tenant === null) {
            throw new TenantCouldNotBeIdentifiedOnDomainException($args[0]);
        }

        return $tenant;
    }

    public function resolved(TenantContract $tenant, ...$args): void
    {
        //
    }

    /**
     * @return list<array{string}>
     */
    public function getArgsForTenant(TenantContract $tenant): array
    {
        /** @var Tenant $tenant */
        return [[$tenant->subdomain]];
    }
}
