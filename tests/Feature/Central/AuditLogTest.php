<?php

namespace Tests\Feature\Central;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CentralUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = AuditLog::record(AuditAction::CentralLogin, CentralUser::factory()->create());

        try {
            $entry->update(['actor_email' => 'someone@else.test']);
            $this->fail('update must be refused');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_central_sign_in_failure_and_sign_out_are_audited(): void
    {
        $admin = CentralUser::factory()->create(['email' => 'root@tenancey.test']);

        $this->post($this->centralUrl('/login'), ['email' => 'root@tenancey.test', 'password' => 'wrong']);
        $this->post($this->centralUrl('/login'), ['email' => 'root@tenancey.test', 'password' => 'password']);
        $this->post($this->centralUrl('/logout'));

        $this->assertSame(
            [AuditAction::CentralLoginFailed, AuditAction::CentralLogin, AuditAction::CentralLogout],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('root@tenancey.test', AuditLog::query()->where('action', AuditAction::CentralLoginFailed)->sole()->metadata['email']);
        $this->assertSame($admin->id, AuditLog::query()->where('action', AuditAction::CentralLogin)->sole()->actor_id);
    }

    public function test_tenant_actions_are_audited_without_secrets(): void
    {
        Mail::fake();
        $admin = CentralUser::factory()->create();
        $this->actingAs($admin, 'central');

        $this->post($this->centralUrl('/tenants'), [
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'admin_name' => 'Alice',
            'admin_email' => 'alice@acme.test',
            'admin_password' => 'Secret123!',
            'admin_password_confirmation' => 'Secret123!',
            'dedicated_db_user' => '1',
            'db_username' => 'tx_acme_db',
            'db_password' => 'DbSecret123!',
        ])->assertSessionHasNoErrors();
        $tenant = $this->tenant('acme');

        $this->put($this->centralUrl('/tenants/'.$tenant->uuid), ['name' => 'Acme Group']);
        $this->post($this->centralUrl('/tenants/'.$tenant->uuid.'/toggle'));
        $this->post($this->centralUrl('/tenants/'.$tenant->uuid.'/toggle'));
        $this->delete($this->centralUrl('/tenants/'.$tenant->uuid), ['confirm_subdomain' => 'acme']);

        $entries = AuditLog::query()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $this->assertSame([
            AuditAction::TenantCreated,
            AuditAction::TenantRenamed,
            AuditAction::TenantDisabled,
            AuditAction::TenantEnabled,
            AuditAction::TenantDeleteRequested,
        ], $entries->pluck('action')->all());
        $this->assertTrue($entries->every(fn (AuditLog $entry) => $entry->actor_id === $admin->id));

        $created = $entries->first();
        $this->assertSame('tx_acme_db', $created->metadata['db_username']);
        $this->assertEquals(['from' => 'Acme Ltd', 'to' => 'Acme Group'], $entries[1]->metadata);

        $everything = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('Secret123!', $everything);
        $this->assertStringNotContainsString('DbSecret123!', $everything);
    }

    public function test_the_audit_page_lists_and_filters_entries(): void
    {
        Bus::fake();
        $admin = CentralUser::factory()->create(['email' => 'root@tenancey.test']);
        AuditLog::record(AuditAction::CentralLogin, $admin);
        AuditLog::record(AuditAction::TenantRenamed, $admin, metadata: ['from' => 'Old', 'to' => 'New']);

        $this->actingAs($admin, 'central');

        $this->get($this->centralUrl('/audit'))
            ->assertOk()
            ->assertSee('Admin signed in')
            ->assertSee('Tenant renamed');

        $this->get($this->centralUrl('/audit?action=tenant.renamed'))
            ->assertOk()
            ->assertSee('from: &#039;Old&#039;', false)
            ->assertDontSee('Admin signed in</td>', false);
    }

    public function test_the_log_viewer_is_for_central_admins_on_the_central_domain_only(): void
    {
        $this->get($this->centralUrl('/log-viewer'))->assertRedirect($this->centralUrl('/login'));

        $this->actingAs(CentralUser::factory()->create(), 'central')
            ->get($this->centralUrl('/log-viewer'))
            ->assertOk();

        $this->createTenant('acme', ['admin_email' => 'alice@acme.test']);
        $this->get($this->tenantUrl('acme', '/log-viewer'))->assertNotFound();
    }
}
