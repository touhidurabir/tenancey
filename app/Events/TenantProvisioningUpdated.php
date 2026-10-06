<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Progress of the provisioning chain (also a retry or "Resend credentials" run), on
 * `private-tenants.{uuid}.provisioning` (and the shared `private-tenants` list channel). Who may listen: routes/channels.php.
 */
class TenantProvisioningUpdated extends TenantProgressUpdated
{
    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenants.'.$this->progress['uuid'].'.provisioning'), // the tenant page
            $this->listChannel(),                                         // the /tenants list
        ];
    }
}
