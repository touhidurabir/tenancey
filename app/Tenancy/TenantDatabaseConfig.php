<?php

namespace App\Tenancy;

use Stancl\Tenancy\DatabaseConfig;

/**
 * Stancl copies every `db_*` attribute onto the tenant connection, nulls included, so a tenant
 * without its own MySQL user would connect with username NULL. Dropping nulls lets those keys fall
 * back to the template (central) connection, i.e. the master credentials from .env.
 */
class TenantDatabaseConfig extends DatabaseConfig
{
    /**
     * @return array<string, mixed>
     */
    public function tenantConfig(): array
    {
        return array_filter(parent::tenantConfig(), fn ($value) => $value !== null);
    }
}
