<?php

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A provisioning or teardown chain moved on: a step started, the state changed, or it failed.
 *
 * Broadcast NOW (not queued): it is sent from inside the chain's own queue job, and a queued
 * broadcast would wait behind other jobs, arriving after the step it describes.
 *
 * Each event goes to two channels: its own per-tenant channel (the tenant page) and the shared
 * `tenants` channel (the /tenants list). The payload is the same for both, and holds only what
 * those two pages show (never credentials).
 */
abstract class TenantProgressUpdated implements ShouldBroadcastNow
{
    /**
     * What the browser receives. Copied from the tenant NOW, not read at send time: the job keeps
     * changing the same Tenant object, and a later save must not rewrite an earlier event.
     *
     * @var array{uuid: string, name: string, host: string, show_url: string, login_url: string, enabled: bool, state: string, state_description: string, badge_classes: string, current_step: string|null, last_error: string|null}
     */
    public array $progress;

    public function __construct(Tenant $tenant)
    {
        $this->progress = [
            'uuid' => $tenant->uuid,
            // Row data for the /tenants list, which may not have this tenant yet (a new one).
            'name' => $tenant->name,
            'host' => $tenant->host(),
            'show_url' => route('central.tenants.show', $tenant),
            'login_url' => $tenant->url('/login'),
            'enabled' => $tenant->enabled,
            'state' => $tenant->state->label(),
            'state_description' => $tenant->state->describe(),
            'badge_classes' => $tenant->state->badgeClasses(),
            'current_step' => $tenant->current_step,
            'last_error' => $tenant->last_error,
        ];
    }

    /**
     * The list page's channel, shared by every tenant: `private-tenants`.
     */
    protected function listChannel(): PrivateChannel
    {
        return new PrivateChannel('tenants');
    }

    /**
     * The event name the browser listens for (`.progress.updated` in Echo, the leading dot
     * meaning "this exact name, not an App\Events class").
     */
    public function broadcastAs(): string
    {
        return 'progress.updated';
    }

    /**
     * @return array{uuid: string, name: string, host: string, show_url: string, login_url: string, enabled: bool, state: string, state_description: string, badge_classes: string, current_step: string|null, last_error: string|null}
     */
    public function broadcastWith(): array
    {
        return $this->progress;
    }
}
