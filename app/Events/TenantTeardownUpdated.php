<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Progress of the teardown (delete) chain, on `private-tenants.{uuid}.teardown` (and the shared
 * `private-tenants` list channel). Who may listen: routes/channels.php.
 */
class TenantTeardownUpdated extends TenantProgressUpdated
{
    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenants.'.$this->progress['uuid'].'.teardown'), // the tenant page
            $this->listChannel(),                                         // the /tenants list
        ];
    }
}
