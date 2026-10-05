<?php

namespace App\Tenancy;

use App\Models\Tenant as TenantModel;
use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Stancl's Redis bootstrapper, but the key prefix comes from the tenant's `cache_prefix` column
 * instead of being derived from config + id. Covers every connection in
 * tenancy.redis.prefixed_connections (sessions and cache).
 */
class TenantRedisBootstrapper extends RedisTenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        /** @var TenantModel $tenant */
        foreach ($this->prefixedConnections() as $connection) {
            $client = Redis::connection($connection)->client();

            $this->originalPrefixes[$connection] = $client->getOption(\Redis::OPT_PREFIX);
            $client->setOption(\Redis::OPT_PREFIX, $tenant->cache_prefix);
        }
    }
}
