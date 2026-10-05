<?php

namespace App\Jobs\Teardown;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Redis;

/**
 * Deletes every Redis key under the tenant's prefix (its sessions and cache) on each prefixed
 * connection. Uses SCAN, never KEYS, and only ever matches this tenant's own prefix.
 */
class FlushTenantCache extends TenantJob
{
    public static function label(): string
    {
        return 'Flush cache and sessions';
    }

    protected function process(Tenant $tenant): void
    {
        foreach (config('tenancy.redis.prefixed_connections') as $connection) {
            $client = Redis::connection($connection)->client();
            $originalPrefix = $client->getOption(\Redis::OPT_PREFIX);

            // Scan raw key names: with a client prefix set, phpredis would prefix the pattern too.
            $client->setOption(\Redis::OPT_PREFIX, '');

            try {
                $cursor = null;

                do {
                    $keys = $client->scan($cursor, $tenant->cache_prefix.'*', 500);

                    if (is_array($keys) && $keys !== []) {
                        $client->del($keys);
                    }
                } while ($cursor > 0);
            } finally {
                $client->setOption(\Redis::OPT_PREFIX, $originalPrefix);
            }
        }
    }
}
