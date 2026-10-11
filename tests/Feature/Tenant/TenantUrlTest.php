<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use Tests\TestCase;

class TenantUrlTest extends TestCase
{
    public function test_url_without_a_port_in_app_url(): void
    {
        config(['app.url' => 'http://tenancey.test', 'tenancy.central_domains' => ['tenancey.test']]);

        $this->assertSame('http://acme.tenancey.test/login', $this->acme()->url('/login'));
    }

    public function test_url_keeps_the_port_from_app_url(): void
    {
        config(['app.url' => 'http://tenancey.localhost:8000', 'tenancy.central_domains' => ['tenancey.localhost']]);

        $this->assertSame('http://acme.tenancey.localhost:8000/login', $this->acme()->url('/login'));
    }

    public function test_url_keeps_the_scheme_and_handles_paths(): void
    {
        config(['app.url' => 'https://tenancey.localhost:8443/', 'tenancy.central_domains' => ['tenancey.localhost']]);

        $this->assertSame('https://acme.tenancey.localhost:8443/', $this->acme()->url());
        $this->assertSame('https://acme.tenancey.localhost:8443/dashboard', $this->acme()->url('dashboard'));
    }

    private function acme(): Tenant
    {
        return (new Tenant)->forceFill(['subdomain' => 'acme']);
    }
}
