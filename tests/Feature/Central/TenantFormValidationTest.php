<?php

namespace Tests\Feature\Central;

use App\Models\CentralUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantFormValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->actingAs(CentralUser::factory()->create(), 'central');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSubdomains(): array
    {
        return [
            'too short' => ['ab'],
            'longer than a DNS label' => [str_repeat('a', 64)],
            'leading hyphen' => ['-acme'],
            'trailing hyphen' => ['acme-'],
            'underscore' => ['acme_co'],
            'dot' => ['acme.co'],
            'reserved' => ['admin'],
            'reserved horizon' => ['horizon'],
        ];
    }

    #[DataProvider('invalidSubdomains')]
    public function test_it_rejects_invalid_subdomains(string $subdomain): void
    {
        $this->post($this->centralUrl('/tenants'), $this->validData(['subdomain' => $subdomain]))
            ->assertSessionHasErrors('subdomain');

        $this->assertSame(0, Tenant::withTrashed()->count());
    }

    public function test_it_lowercases_the_subdomain(): void
    {
        $this->post($this->centralUrl('/tenants'), $this->validData(['subdomain' => '  ACME-2 ']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Tenant::query()->where('subdomain', 'acme-2')->count());
    }

    public function test_the_subdomain_must_be_unique(): void
    {
        $this->post($this->centralUrl('/tenants'), $this->validData())->assertSessionHasNoErrors();
        $this->post($this->centralUrl('/tenants'), $this->validData())->assertSessionHasErrors('subdomain');
    }

    public function test_a_deleted_tenants_subdomain_stays_reserved(): void
    {
        $this->post($this->centralUrl('/tenants'), $this->validData())->assertSessionHasNoErrors();
        Tenant::query()->where('subdomain', 'acme')->firstOrFail()->delete(); // soft delete

        $this->post($this->centralUrl('/tenants'), $this->validData())->assertSessionHasErrors('subdomain');
    }

    public function test_the_first_user_fields_are_validated(): void
    {
        $this->post($this->centralUrl('/tenants'), $this->validData([
            'admin_email' => 'not-an-email',
            'admin_password' => 'Secret123!',
            'admin_password_confirmation' => 'Different123!',
        ]))->assertSessionHasErrors(['admin_email', 'admin_password']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'admin_name' => 'Alice',
            'admin_email' => 'alice@acme.test',
            'admin_phone' => '0123',
            'admin_password' => 'Secret123!',
            'admin_password_confirmation' => 'Secret123!',
        ], $overrides);
    }
}
