<?php

namespace App\Jobs\Provisioning;

use App\Jobs\TenantJob;
use App\Models\Tenant;
use App\Models\User;

class CreateFirstTenantUser extends TenantJob
{
    public static function label(): string
    {
        return 'Create first user';
    }

    protected function process(Tenant $tenant): void
    {
        // Null once the credentials email has gone out, so there is nothing left to do.
        $admin = $tenant->pending_admin;

        if ($admin === null) {
            $this->skip('credentials already sent, nothing pending');

            return;
        }

        $user = $this->inTenant($tenant, function () use ($admin) {
            $user = User::query()->firstOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'phone' => $admin['phone'] ?? null,
                    'password' => $admin['password'],
                    'must_change_password' => $admin['must_change_password'] ?? true,
                ],
            );

            $user->syncRoles(['admin']); // role seeded by TenantDatabaseSeeder

            return $user;
        });

        $this->log('info', 'First user ready', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => 'admin',
            'must_change_password' => $user->must_change_password,
        ]);
    }
}
