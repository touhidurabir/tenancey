<?php

namespace Tests\Feature\Central;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CentralUser;
use App\Models\ImpersonationToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    private CentralUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = CentralUser::factory()->create(['email' => 'root@tenancey.test']);
    }

    public function test_an_admin_enters_a_tenant_as_its_first_admin(): void
    {
        $tenant = $this->createTenant('acme');
        $this->addUser($tenant, 'bob@acme.test', 'member');

        $link = $this->requestLink($tenant);

        $this->assertMatchesRegularExpression('#^http://acme\.tenancey\.test/impersonate/[A-Za-z0-9]{64}$#', $link);
        $token = basename($link);
        $this->assertNull(ImpersonationToken::query()->where('token_hash', $token)->first(), 'only the hash is stored');
        $this->assertNotNull(ImpersonationToken::query()->where('token_hash', ImpersonationToken::hash($token))->first());

        $this->get($link)->assertRedirect($this->tenantUrl('acme', '/dashboard'));

        // Alice must change her password, but not while an admin is signed in as her.
        $this->get($this->tenantUrl('acme', '/dashboard'))
            ->assertOk()
            ->assertSee('You are impersonating')
            ->assertSee('alice@acme.test')
            ->assertSee('root@tenancey.test');
        $this->get($this->tenantUrl('acme', '/password/change'))->assertForbidden();

        $this->assertSame(
            [AuditAction::ImpersonationRequested, AuditAction::ImpersonationStarted],
            AuditLog::query()->orderBy('id')->where('action', 'like', 'impersonation.%')->get()->pluck('action')->all(),
        );
        $started = AuditLog::query()->where('action', AuditAction::ImpersonationStarted)->sole();
        $this->assertSame($this->admin->id, $started->actor_id);
        $this->assertSame($tenant->id, $started->tenant_id);
        $this->assertSame('alice@acme.test', $started->subject_label);
    }

    public function test_ending_returns_to_the_central_tenant_page_and_is_audited(): void
    {
        $tenant = $this->createTenant('acme');
        $this->get($this->requestLink($tenant));

        $this->travel(5)->minutes();

        $this->post($this->tenantUrl('acme', '/impersonate/end'))
            ->assertRedirect($this->centralUrl('/tenants/'.$tenant->uuid));

        $this->assertGuest('web');
        $ended = AuditLog::query()->where('action', AuditAction::ImpersonationEnded)->sole();
        $this->assertSame($this->admin->id, $ended->actor_id);
        $this->assertGreaterThanOrEqual(300, $ended->metadata['duration_seconds']);
    }

    public function test_logging_out_while_impersonating_ends_it(): void
    {
        $tenant = $this->createTenant('acme');
        $this->get($this->requestLink($tenant));

        $this->post($this->tenantUrl('acme', '/logout'))
            ->assertRedirect($this->centralUrl('/tenants/'.$tenant->uuid));

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ImpersonationEnded)->count());
    }

    public function test_the_session_expires(): void
    {
        $tenant = $this->createTenant('acme');
        $this->get($this->requestLink($tenant));

        $this->travel(config('tenancy.impersonation.session_ttl') + 1)->seconds();

        $this->get($this->tenantUrl('acme', '/dashboard'))
            ->assertRedirect($this->centralUrl('/tenants/'.$tenant->uuid));

        $this->assertGuest('web');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ImpersonationExpired)->count());
    }

    public function test_a_link_works_once(): void
    {
        $tenant = $this->createTenant('acme');
        $link = $this->requestLink($tenant);

        $this->get($link)->assertRedirect();
        $this->post($this->tenantUrl('acme', '/impersonate/end'));

        $this->get($link)->assertForbidden();
        $this->assertRejected('link already used');
    }

    public function test_a_link_expires(): void
    {
        $tenant = $this->createTenant('acme');
        $link = $this->requestLink($tenant);

        $this->travel(config('tenancy.impersonation.token_ttl') + 1)->seconds();

        $this->get($link)->assertForbidden();
        $this->assertGuest('web');
        $this->assertRejected('link expired');
    }

    public function test_a_link_only_works_on_its_own_tenant(): void
    {
        $acme = $this->createTenant('acme');
        $this->createTenant('beta');
        $link = $this->requestLink($acme);

        $this->get(str_replace('acme.', 'beta.', $link))->assertForbidden();
        $this->assertRejected('link issued for another tenant');

        $this->get($link)->assertRedirect(); // still unused on acme
    }

    public function test_unknown_links_are_rejected(): void
    {
        $this->createTenant('acme');

        $this->get($this->tenantUrl('acme', '/impersonate/'.str_repeat('a', 64)))->assertForbidden();
        $this->assertRejected('unknown link');
    }

    public function test_only_enabled_ready_tenants_can_be_entered(): void
    {
        $tenant = $this->createTenant('acme');
        $tenant->forceFill(['enabled' => false])->save();

        $this->actingAs($this->admin, 'central')
            ->from($this->centralUrl('/tenants/'.$tenant->uuid))
            ->post($this->centralUrl('/tenants/'.$tenant->uuid.'/impersonate'))
            ->assertRedirect($this->centralUrl('/tenants/'.$tenant->uuid))
            ->assertSessionHas('error', 'Cannot impersonate: The tenant must be enabled and ready.');

        $this->assertSame(0, ImpersonationToken::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ImpersonationRefused)->count());
    }

    public function test_a_tenant_without_an_admin_cannot_be_entered(): void
    {
        $tenant = $this->createTenant('acme');
        $tenant->run(fn () => User::query()->sole()->syncRoles(['member']));
        tenancy()->end();

        $this->actingAs($this->admin, 'central')
            ->post($this->centralUrl('/tenants/'.$tenant->uuid.'/impersonate'))
            ->assertSessionHas('error', 'Cannot impersonate: The tenant has no user with the admin role.');

        $this->assertSame(0, ImpersonationToken::query()->count());
    }

    public function test_guests_and_tenant_users_cannot_request_links(): void
    {
        $tenant = $this->createTenant('acme');

        $this->post($this->centralUrl('/tenants/'.$tenant->uuid.'/impersonate'))
            ->assertRedirect($this->centralUrl('/login'));

        $this->assertSame(0, ImpersonationToken::query()->count());
    }

    /**
     * Request a link as the central admin; returns where it redirects (the tenant's subdomain).
     */
    private function requestLink(Tenant $tenant): string
    {
        /** @var TestResponse $response */
        $response = $this->actingAs($this->admin, 'central')
            ->post($this->centralUrl('/tenants/'.$tenant->uuid.'/impersonate'));
        $response->assertRedirect();

        auth()->shouldUse('web'); // actingAs(..., 'central') switched the default guard; tenant routes use `web`

        return $response->headers->get('Location');
    }

    private function addUser(Tenant $tenant, string $email, string $role): void
    {
        $tenant->run(fn () => User::query()->create([
            'name' => 'Bob', 'email' => $email, 'password' => 'Secret123!',
        ])->assignRole($role));
        tenancy()->end();
    }

    private function assertRejected(string $reason): void
    {
        $rejected = AuditLog::query()->where('action', AuditAction::ImpersonationRejected)->latest('id')->firstOrFail();

        $this->assertSame($reason, $rejected->metadata['reason']);
    }
}
