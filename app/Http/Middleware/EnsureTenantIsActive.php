<?php

namespace App\Http\Middleware;

use App\Enums\TenantState;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an enabled, ready tenant's subdomain is open, login page included. Checked on every
 * request, so disabling a tenant shuts out its logged-in users on their next click.
 * (Soft-deleted tenants never get this far: the resolver does not find them.)
 */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        if ($tenant->isAccessible()) {
            return $next($request);
        }

        if ($tenant->enabled && $tenant->state === TenantState::Provisioning) {
            return response()->view('tenant.provisioning', ['tenant' => $tenant], 503);
        }

        return response()->view('tenant.unavailable', ['tenant' => $tenant], 403);
    }
}
