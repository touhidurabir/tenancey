<?php

namespace Tests\Feature\Provisioning;

use App\Enums\TenantState;
use App\Mail\TenantCredentialsMail;
use App\Services\TenantProvisioner;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ProvisioningFailureTest extends TestCase
{
    public function test_a_failure_before_ready_marks_the_tenant_failed_and_retry_resumes(): void
    {
        Mail::fake();
        $this->app->bind(TenantDatabaseSeeder::class, fn () => new class extends Seeder
        {
            public function run(): void
            {
                throw new RuntimeException('seeder exploded');
            }
        });

        try {
            $this->createTenant('acme');
            $this->fail('Provisioning should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('seeder exploded', $e->getMessage());
        }

        // A failing step must not leave the (long-lived) worker inside the tenant's context.
        $this->assertFalse(tenancy()->initialized);

        $tenant = $this->tenant('acme');
        $this->assertSame(TenantState::Failed, $tenant->state);
        $this->assertSame('[Seed database] seeder exploded', $tenant->last_error);
        $this->assertNotNull($tenant->pending_admin);
        Mail::assertNothingSent();

        $this->get($this->tenantUrl('acme', '/login'))->assertForbidden();
        tenancy()->end();

        // Fix the cause and retry: earlier steps skip, the chain finishes.
        $this->app->offsetUnset(TenantDatabaseSeeder::class);
        $provisioner = app(TenantProvisioner::class);
        $this->assertTrue($provisioner->canRetry($tenant));
        $provisioner->retry($tenant);

        $tenant->refresh();
        $this->assertSame(TenantState::Ready, $tenant->state);
        $this->assertNull($tenant->last_error);
        $this->assertNull($tenant->pending_admin);
        Mail::assertSent(TenantCredentialsMail::class);
    }

    public function test_a_mail_failure_keeps_the_tenant_ready_and_credentials_can_be_resent(): void
    {
        // Simulate the mail server being down: the message is aborted just before sending.
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('smtp down'));

        try {
            $this->createTenant('acme');
        } catch (RuntimeException) {
            // expected: the sync queue rethrows after the chain's catch() ran
        }

        $tenant = $this->tenant('acme');
        $this->assertSame(TenantState::Ready, $tenant->state, 'a working tenant must not be locked out by mail');
        $this->assertStringStartsWith('[Send credentials email] smtp down', $tenant->last_error);
        $this->assertNotNull($tenant->pending_admin, 'kept so the email can be resent');

        Event::forget(MessageSending::class);
        Mail::fake();
        $provisioner = app(TenantProvisioner::class);
        $this->assertTrue($provisioner->canRetry($tenant));
        $provisioner->retry($tenant);

        $tenant->refresh();
        $this->assertNull($tenant->pending_admin);
        $this->assertNull($tenant->last_error);
        Mail::assertSent(TenantCredentialsMail::class);
    }
}
