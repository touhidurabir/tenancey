<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Mail\TenantCredentialsMail;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the first user their login details, then forgets the plaintext password. If sending
 * fails, pending_admin is kept so "Resend credentials" can try again.
 */
class SendTenantCredentials extends TenantJob
{
    public static function label(): string
    {
        return 'Send credentials email';
    }

    protected function process(Tenant $tenant): void
    {
        $admin = $tenant->pending_admin;

        if ($admin !== null) {
            Mail::to($admin['email'], $admin['name'])->send(new TenantCredentialsMail($tenant, $admin));
            $this->log('info', 'Credentials email sent', ['to' => $admin['email']]);
        } else {
            $this->skip('credentials already sent');
        }

        $tenant->forceFill([
            'pending_admin' => null,
            'current_step' => null,
            'last_error' => null,
        ])->save();

        $this->log('notice', 'Provisioning run finished', ['state' => $tenant->state->label(), 'url' => $tenant->url('/login')]);
    }
}
