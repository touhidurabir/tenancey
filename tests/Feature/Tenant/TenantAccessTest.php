<?php

namespace Tests\Feature\Tenant;

use App\Enums\TenantState;
use App\Models\CentralUser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_an_unknown_subdomain_shows_no_such_workspace(): void
    {
        $this->get($this->tenantUrl('ghost', '/login'))
            ->assertNotFound()
            ->assertSee('No such workspace');
    }

    public function test_tenant_routes_do_not_exist_on_the_central_domain(): void
    {
        $this->get($this->centralUrl('/dashboard'))->assertNotFound();
    }

    public function test_the_door_follows_enabled_and_state(): void
    {
        $tenant = $this->createTenant('acme');
        $login = $this->tenantUrl('acme', '/login');

        $this->get($login)->assertOk()->assertSee('Sign in to Acme Ltd');

        foreach ([
            [true, TenantState::Provisioning, 503, 'being set up'],
            [true, TenantState::Failed, 403, 'unavailable'],
            [true, TenantState::Deleting, 403, 'unavailable'],
            [false, TenantState::Ready, 403, 'unavailable'],
            [false, TenantState::Provisioning, 403, 'unavailable'],
        ] as [$enabled, $state, $code, $text]) {
            $tenant->forceFill(['enabled' => $enabled, 'state' => $state])->save();
            tenancy()->end();

            $this->get($login)->assertStatus($code)->assertSee($text);
        }
    }

    public function test_first_sign_in_forces_a_password_change(): void
    {
        $tenant = $this->createTenant('acme');

        $this->post($this->tenantUrl('acme', '/login'), ['email' => 'alice@acme.test', 'password' => 'Secret123!'])
            ->assertRedirect($this->tenantUrl('acme', '/dashboard'));

        $this->get($this->tenantUrl('acme', '/dashboard'))->assertRedirect($this->tenantUrl('acme', '/password/change'));

        $this->put($this->tenantUrl('acme', '/password/change'), [
            'current_password' => 'Secret123!',
            'password' => 'NewSecret456!',
            'password_confirmation' => 'NewSecret456!',
        ])->assertRedirect($this->tenantUrl('acme', '/dashboard'));

        $this->get($this->tenantUrl('acme', '/dashboard'))
            ->assertOk()
            ->assertSee('Welcome, Alice')
            ->assertSee($tenant->db_name)
            ->assertSee('admin');
    }

    public function test_a_tenant_user_cannot_sign_in_to_another_tenant_or_central(): void
    {
        $this->createTenant('acme');
        $this->createTenant('beta');

        $this->post($this->tenantUrl('beta', '/login'), ['email' => 'alice@acme.test', 'password' => 'Secret123!'])
            ->assertSessionHasErrors('email');
        tenancy()->end();

        $this->post($this->centralUrl('/login'), ['email' => 'alice@acme.test', 'password' => 'Secret123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('central');
    }

    public function test_a_central_admin_cannot_sign_in_to_a_tenant(): void
    {
        CentralUser::factory()->create(['email' => 'admin@tenancey.test']);
        $this->createTenant('acme');

        $this->post($this->tenantUrl('acme', '/login'), ['email' => 'admin@tenancey.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_failed_logins_on_one_tenant_do_not_lock_out_another(): void
    {
        $this->createTenant('acme');
        $this->createTenant('beta', ['admin_email' => 'shared@example.test']);
        $this->tenant('acme')->run(fn () => User::query()->update(['email' => 'shared@example.test']));

        for ($i = 0; $i < 6; $i++) {
            $this->post($this->tenantUrl('acme', '/login'), ['email' => 'shared@example.test', 'password' => 'wrong']);
            tenancy()->end();
        }
        $this->post($this->tenantUrl('acme', '/login'), ['email' => 'shared@example.test', 'password' => 'Secret123!'])
            ->assertSessionHasErrors('email');
        tenancy()->end();

        $this->post($this->tenantUrl('beta', '/login'), ['email' => 'shared@example.test', 'password' => 'Secret123!'])
            ->assertRedirect($this->tenantUrl('beta', '/dashboard'));
    }
}
