<?php

namespace App\Jobs\Provisioning;

use App\Enums\TenantState;
use App\Jobs\TenantJob;
use App\Models\Tenant;

/**
 * Opens the tenant's subdomain (when it is also enabled). Runs before the credentials email so
 * the login works by the time the email arrives.
 */
class MarkTenantReady extends TenantJob
{
    public static function label(): string
    {
        return 'Mark tenant ready';
    }

    protected function process(Tenant $tenant): void
    {
        if (! in_array($tenant->state, [TenantState::Provisioning, TenantState::Failed], true)) {
            $this->skip("state is already {$tenant->state->label()}");

            return; // already ready (e.g. a "Resend credentials" run)
        }

        $tenant->forceFill([
            'state' => TenantState::Ready,
            'provisioned_at' => $tenant->provisioned_at ?? now(),
            'last_error' => null,
        ])->save();
    }
}
