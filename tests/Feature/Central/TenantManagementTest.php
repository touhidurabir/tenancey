<?php

namespace Tests\Feature\Central;

use App\Enums\TenantState;
use App\Jobs\Provisioning\CreateTenantDatabase;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Services\TenantTeardown;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    public function test_guests_cannot_manage_tenants(): void
    {
        $this->get($this->centralUrl('/tenants/create'))->assertRedirect($this->centralUrl('/login'));
        $this->post($this->centralUrl('/tenants'), [])->assertRedirect($this->centralUrl('/login'));
    }

    public function test_the_tenant_web_guard_never_authorises_central_routes(): void
    {
        $this->actingAs(new User(['email' => 'x@y.z']), 'web')
            ->get($this->centralUrl('/tenants'))
            ->assertRedirect($this->centralUrl('/login'));
    }

    public function test_central_admins_can_sign_in(): void
    {
        CentralUser::factory()->create(['email' => 'admin@tenancey.test']);

        $this->post($this->centralUrl('/login'), ['email' => 'admin@tenancey.test', 'password' => 'password'])
            ->assertRedirect($this->centralUrl('/tenants'));

        $this->assertAuthenticated('central');
    }

    public function test_creating_a_tenant_saves_it_and_dispatches_the_provisioning_chain(): void
    {
        Bus::fake();
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $response = $this->post($this->centralUrl('/tenants'), [
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'admin_name' => 'Alice',
            'admin_email' => 'alice@acme.test',
            'admin_phone' => '0123',
            'admin_password' => 'Secret123!',
            'admin_password_confirmation' => 'Secret123!',
        ]);

        $tenant = $this->tenant('acme');
        $response->assertRedirect($this->centralUrl('/tenants/'.$tenant->uuid));

        $this->assertIsInt($tenant->id);
        $this->assertTrue(Str::isUuid($tenant->uuid));
        $this->assertTrue($tenant->enabled);
        $this->assertSame(TenantState::Provisioning, $tenant->state);
        $this->assertSame('tenanceytest_'.$tenant->uuid, $tenant->db_name);
        $this->assertNull($tenant->db_username, 'no dedicated MySQL user: master .env credentials');
        $this->assertNull($tenant->db_password);
        $this->assertSame('storage/testtenant_'.$tenant->uuid, $tenant->storage_path);
        $this->assertSame('tenanceytest_'.$tenant->uuid.':', $tenant->cache_prefix);
        $this->assertSame('alice@acme.test', $tenant->pending_admin['email']);

        // Secrets are encrypted at rest.
        $this->assertStringNotContainsString('Secret123!', DB::table('tenants')->where('id', $tenant->id)->value('pending_admin'));

        Bus::assertChained(TenantProvisioner::STEPS);
        Bus::assertDispatched(CreateTenantDatabase::class, fn (CreateTenantDatabase $job) => $job->tenantId === $tenant->id
            && $job->connection === 'provisioning' && $job->queue === 'provisioning'
            && $job->chainConnection === 'provisioning' && $job->chainQueue === 'provisioning');
    }

    public function test_a_tenant_can_be_renamed_disabled_and_enabled(): void
    {
        Mail::fake();
        $tenant = $this->createTenant();
        $this->actingAs(CentralUser::factory()->create(), 'central');
        $url = $this->centralUrl('/tenants/'.$tenant->uuid);

        $this->put($url, ['name' => 'Acme Group'])->assertSessionHasNoErrors();
        $this->assertSame('Acme Group', $tenant->fresh()->name);

        $this->post($url.'/toggle');
        $this->assertFalse($tenant->fresh()->enabled);
        $this->assertSame(TenantState::Ready, $tenant->fresh()->state, 'enabled is independent of state');

        $this->post($url.'/toggle');
        $this->assertTrue($tenant->fresh()->enabled);
    }

    public function test_deleting_requires_typing_the_subdomain(): void
    {
        Bus::fake();
        $tenant = Tenant::query()->create([
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'state' => TenantState::Ready,
            'db_name' => 'tenanceytest_x',
            'storage_path' => 'storage/testtenant_x',
            'cache_prefix' => 'tenanceytest_x:',
        ]);
        $this->actingAs(CentralUser::factory()->create(), 'central');
        $url = $this->centralUrl('/tenants/'.$tenant->uuid);

        $this->delete($url, ['confirm_subdomain' => 'nope'])->assertSessionHasErrors('confirm_subdomain');
        Bus::assertNothingDispatched();

        $this->delete($url, ['confirm_subdomain' => 'acme']);
        Bus::assertChained(TenantTeardown::STEPS);
        $this->assertSame(TenantState::Deleting, $tenant->fresh()->state);
    }

    public function test_deleted_tenants_stay_listed_and_viewable_as_records(): void
    {
        Mail::fake();
        $tenant = $this->createTenant();
        app(TenantTeardown::class)->teardown($tenant);
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $this->get($this->centralUrl('/tenants'))->assertOk()->assertSee('Acme Ltd')->assertSee('Deleted');
        $this->get($this->centralUrl('/tenants/'.$tenant->uuid))->assertOk()->assertSee(TenantState::Deleted->describe());
        $this->post($this->centralUrl('/tenants/'.$tenant->uuid.'/toggle'))->assertNotFound();
    }
}
