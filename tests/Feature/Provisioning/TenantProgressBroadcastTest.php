<?php

namespace Tests\Feature\Provisioning;

use App\Events\TenantProvisioningUpdated;
use App\Events\TenantTeardownUpdated;
use App\Models\CentralUser;
use App\Services\TenantProvisioner;
use App\Services\TenantTeardown;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The admin's tenant page follows provisioning and teardown live: every step change is broadcast
 * on the tenant's own private channel, one channel per chain.
 */
class TenantProgressBroadcastTest extends TestCase
{
    public function test_provisioning_broadcasts_every_step_on_the_provisioning_channel(): void
    {
        Mail::fake();
        Event::fake([TenantProvisioningUpdated::class, TenantTeardownUpdated::class]);

        $tenant = $this->createTenant('acme');

        $steps = [];
        Event::assertDispatched(TenantProvisioningUpdated::class, function (TenantProvisioningUpdated $event) use ($tenant, &$steps) {
            $this->assertSame(['private-tenants.'.$tenant->uuid.'.provisioning', 'private-tenants'], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
            $steps[] = $event->broadcastWith()['current_step'];

            return true;
        });

        $labels = array_map(fn (string $job) => $job::label(), TenantProvisioner::STEPS);
        $this->assertSame($labels, array_values(array_unique(array_filter($steps))));
        $this->assertNull($steps[0], 'the first event is the new row, before any step');
        $this->assertNull(end($steps), 'the last event ends the run');
        Event::assertNotDispatched(TenantTeardownUpdated::class);
    }

    public function test_teardown_broadcasts_on_the_teardown_channel(): void
    {
        Mail::fake();
        $tenant = $this->createTenant('acme');
        Event::fake([TenantProvisioningUpdated::class, TenantTeardownUpdated::class]);

        app(TenantTeardown::class)->teardown($tenant);

        Event::assertDispatched(TenantTeardownUpdated::class, fn (TenantTeardownUpdated $event) => array_map(fn ($channel) => $channel->name, $event->broadcastOn()) === ['private-tenants.'.$tenant->uuid.'.teardown', 'private-tenants']);
        Event::assertDispatched(TenantTeardownUpdated::class, fn (TenantTeardownUpdated $event) => $event->broadcastWith()['state'] === 'Deleted');
        Event::assertNotDispatched(TenantProvisioningUpdated::class);
    }

    public function test_the_payload_never_carries_credentials(): void
    {
        Mail::fake();
        Event::fake([TenantProvisioningUpdated::class]);

        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);

        Event::assertDispatched(TenantProvisioningUpdated::class, function (TenantProvisioningUpdated $event) use ($tenant) {
            $this->assertSame(['uuid', 'name', 'host', 'show_url', 'login_url', 'enabled', 'state', 'state_description', 'badge_classes', 'current_step', 'last_error'], array_keys($event->broadcastWith()));
            $this->assertStringNotContainsString($tenant->db_password, json_encode($event->broadcastWith()));

            return true;
        });
    }

    public function test_only_central_admins_may_subscribe(): void
    {
        $this->useReverbBroadcaster();

        $channels = ['private-tenants.some-uuid.provisioning', 'private-tenants.some-uuid.teardown', 'private-tenants'];

        foreach ($channels as $channel) {
            $this->post($this->centralUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel])
                ->assertForbidden();
        }

        $this->actingAs(CentralUser::factory()->create(), 'central');

        foreach ($channels as $channel) {
            $this->post($this->centralUrl('/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel])
                ->assertOk()
                ->assertJsonStructure(['auth']);
        }
    }

    public function test_the_tenants_list_has_the_hooks_the_live_script_needs(): void
    {
        Mail::fake();
        $tenant = $this->createTenant('acme');
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $this->get($this->centralUrl('/tenants'))
            ->assertOk()
            ->assertSee('data-tenant-row="'.$tenant->uuid.'"', false)
            ->assertSee('data-tenant-row-template', false)
            ->assertDontSee('http-equiv="refresh"', false);
    }

    /**
     * Tests run with the `null` broadcaster, which authorizes nothing. Switch to Reverb (no server
     * needed: signing a subscription is local) and register the channels on it again.
     */
    private function useReverbBroadcaster(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => ['host' => 'localhost', 'port' => 8081, 'scheme' => 'http', 'useTLS' => false],
        ]);
        Broadcast::forgetDrivers();

        require base_path('routes/channels.php');
    }
}
