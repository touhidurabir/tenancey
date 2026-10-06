<?php

use App\Models\CentralUser;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private channels are authorized by POST /broadcasting/auth (registered with the `web`
 * middleware in bootstrap/app.php). Echo calls it with the session cookie when subscribing.
 *
 * Tenant progress is for central admins only, so these channels check the `central` guard.
 * The default guard (`web`, tenant users) is never consulted here.
 */
Broadcast::channel(
    'tenants.{uuid}.provisioning',
    fn (CentralUser $admin, string $uuid) => true,
    ['guards' => ['central']]
);

Broadcast::channel(
    'tenants.{uuid}.teardown',
    fn (CentralUser $admin, string $uuid) => true,
    ['guards' => ['central']]
);

// The /tenants list: every tenant's provisioning and teardown updates, on one channel.
Broadcast::channel('tenants', fn (CentralUser $admin) => true, ['guards' => ['central']]);
