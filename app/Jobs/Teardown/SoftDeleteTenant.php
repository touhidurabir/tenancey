<?php

namespace App\Jobs\Teardown;

use App\Enums\TenantState;
use App\Jobs\TenantJob;
use App\Models\Tenant;

/**
 * Last step: the resources are gone, so mark the tenant Deleted and soft delete its row. The row
 * stays as the record, and its subdomain stays reserved. Overrides perform() because a retry
 * after success finds no (non-trashed) row and must not fail.
 */
class SoftDeleteTenant extends TenantJob
{
    public static function label(): string
    {
        return 'Mark tenant deleted';
    }

    protected function perform(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant !== null) {
            $this->process($tenant);
        } else {
            $this->skip('tenant already deleted');
        }
    }

    protected function process(Tenant $tenant): void
    {
        $tenant->forceFill([
            'state' => TenantState::Deleted,
            'current_step' => null,
            'last_error' => null,
        ])->save();

        $tenant->delete(); // soft delete

        $this->log('notice', 'Teardown run finished: tenant marked deleted, record kept');
    }
}
