<?php

namespace Tests\Concerns;

use App\Models\User;

trait SignsInTenantUsers
{
    /**
     * Provision a tenant and sign in as its first user (password already changed).
     */
    protected function signInAs(string $subdomain = 'acme'): User
    {
        $tenant = $this->createTenant($subdomain);

        $user = $tenant->run(function () {
            $user = User::query()->firstOrFail();
            $user->forceFill(['must_change_password' => false])->save();

            return $user;
        });

        auth()->shouldUse('web');
        $this->actingAs($user, 'web');

        return $user;
    }
}
