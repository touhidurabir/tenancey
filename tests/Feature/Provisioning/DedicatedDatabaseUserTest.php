<?php

namespace Tests\Feature\Provisioning;

use App\Casts\TenantSecret;
use App\Enums\TenantState;
use App\Jobs\Provisioning\CreateTenantDatabaseUser;
use App\Jobs\Teardown\DropTenantDatabaseUser;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantTeardown;
use App\Tenancy\Database\DatabaseCredentials;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DedicatedDatabaseUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_generated_credentials_follow_the_rules(): void
    {
        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);

        $this->assertSame(TenantState::Ready, $tenant->state);
        $this->assertSame(DatabaseCredentials::USERNAME_LENGTH, strlen($tenant->db_username));
        $this->assertMatchesRegularExpression('/^tx_[a-z][a-z0-9]{12}$/', $tenant->db_username);
        $this->assertGreaterThanOrEqual(12, strlen($tenant->db_password));
        $this->assertLessThanOrEqual(24, strlen($tenant->db_password));
        $this->assertTrue($tenant->db_master_access, 'master access defaults to yes');

        $this->assertSame(1, $this->probe($tenant->db_username, $tenant->db_password, $tenant->db_name));
    }

    public function test_generated_passwords_mix_every_character_class(): void
    {
        foreach (range(1, 50) as $ignored) {
            $password = DatabaseCredentials::generatePassword();

            $this->assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{12,24}$/', $password);
            $this->assertDoesNotMatchRegularExpression('/[\'"\\\\`\s]/', $password);
        }
    }

    public function test_chosen_credentials_are_quoted_not_interpolated(): void
    {
        // Quotes, backslash and a statement separator: interpolated into CREATE USER this would
        // break the statement (stancl's manager did exactly that).
        $password = "pa'ss\"w\\rd;-- x";

        $tenant = $this->createTenant('acme', ['db_username' => 'tx_acme_db', 'db_password' => $password, 'db_master_access' => false], dedicatedDatabaseUser: true);

        $this->assertSame(TenantState::Ready, $tenant->state, (string) $tenant->last_error);
        $this->assertSame('tx_acme_db', $tenant->db_username);
        $this->assertSame($password, $tenant->db_password);
        $this->assertFalse($tenant->db_master_access);
        $this->assertSame(1, $this->probe('tx_acme_db', $password, $tenant->db_name));
    }

    public function test_the_master_users_privileges_are_never_changed(): void
    {
        $grantsBefore = $this->masterGrants();

        $tenant = $this->createTenant('acme', ['db_master_access' => false], dedicatedDatabaseUser: true);
        app(TenantTeardown::class)->teardown($tenant);

        $this->assertSame($grantsBefore, $this->masterGrants());
    }

    public function test_the_password_is_encrypted_with_the_tenant_key(): void
    {
        Config::set('tenancy.database.secret_key', 'base64:'.base64_encode(random_bytes(32)));

        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);
        $raw = DB::table('tenants')->where('id', $tenant->id)->value('db_password');

        $this->assertStringNotContainsString($tenant->db_password, $raw);
        $this->assertSame($tenant->db_password, TenantSecret::encrypter()->decryptString($raw));

        $this->expectException(DecryptException::class);
        Crypt::decryptString($raw); // APP_KEY cannot read it
    }

    public function test_without_a_tenant_key_app_key_is_used(): void
    {
        Config::set('tenancy.database.secret_key', null);

        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);
        $raw = DB::table('tenants')->where('id', $tenant->id)->value('db_password');

        $this->assertSame($tenant->db_password, Crypt::decryptString($raw));
    }

    public function test_the_form_refuses_a_username_that_already_exists_on_the_server(): void
    {
        DB::statement("CREATE USER 'tx_taken'@'%' IDENTIFIED BY 'Whatever123!'");
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $this->post($this->centralUrl('/tenants'), $this->formData(['db_username' => 'tx_taken']))
            ->assertSessionHasErrors(['db_username' => 'A database user with that name already exists on the server.']);

        $this->assertSame(0, Tenant::withTrashed()->count());
    }

    public function test_provisioning_never_adopts_or_drops_a_user_it_did_not_create(): void
    {
        // The server is shared: a same-named user appearing after the form was checked must
        // stop provisioning, and teardown must leave it alone.
        $tenant = $this->createTenant('acme', ['db_username' => 'tx_taken'], dedicatedDatabaseUser: true);
        DB::statement("DROP USER 'tx_taken'@'%'");
        DB::table('tenants')->where('id', $tenant->id)->update(['db_user_created_at' => null]);
        DB::statement("CREATE USER 'tx_taken'@'%' IDENTIFIED BY 'SomeoneElse123!'");

        try {
            (new CreateTenantDatabaseUser($tenant->id))->handle();
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already exists and was not created for this tenant', $e->getMessage());
        }

        (new DropTenantDatabaseUser($tenant->id))->handle();

        $this->assertNotNull(DB::selectOne("SELECT 1 FROM mysql.user WHERE user = 'tx_taken'"), 'someone else\'s user survives');
        $this->assertSame(1, $this->probe('tx_taken', 'SomeoneElse123!', null));
    }

    public function test_a_retry_recreates_its_own_user(): void
    {
        $tenant = $this->createTenant('acme', ['db_username' => 'tx_acme_db', 'db_password' => 'Original123!'], dedicatedDatabaseUser: true);

        (new CreateTenantDatabaseUser($tenant->id))->handle(); // ours: dropped and recreated

        $this->assertSame(1, $this->probe('tx_acme_db', 'Original123!', $tenant->db_name));
    }

    /**
     * @return array<string, mixed>
     */
    public static function invalidUsernames(): array
    {
        return [
            'starts with a digit' => ['1acme'],
            'hyphen' => ['tx-acme'],
            'too long' => ['tx_'.str_repeat('a', 30)],
            'reserved' => ['root'],
            'the master .env user' => [null],
        ];
    }

    #[DataProvider('invalidUsernames')]
    public function test_the_form_validates_the_username(?string $username): void
    {
        $this->actingAs(CentralUser::factory()->create(), 'central');
        $username ??= config('database.connections.mysql.username');

        $this->post($this->centralUrl('/tenants'), $this->formData(['db_username' => $username]))
            ->assertSessionHasErrors('db_username');
    }

    public function test_the_form_validates_the_password_length_and_unique_usernames(): void
    {
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $this->post($this->centralUrl('/tenants'), $this->formData(['db_password' => 'short']))
            ->assertSessionHasErrors('db_password');

        $this->createTenant('beta', ['db_username' => 'tx_shared'], dedicatedDatabaseUser: true);
        $this->post($this->centralUrl('/tenants'), $this->formData(['db_username' => 'tx_shared']))
            ->assertSessionHasErrors(['db_username' => 'Another tenant already uses (or used) that database username.']);
    }

    public function test_database_fields_are_ignored_without_the_checkbox(): void
    {
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $this->post($this->centralUrl('/tenants'), $this->formData([
            'dedicated_db_user' => '0',
            'db_username' => 'root', // would be rejected if it were looked at
            'db_password' => 'x',
            'db_master_access' => '0',
        ]))->assertSessionHasNoErrors();

        $tenant = $this->tenant('acme');
        $this->assertNull($tenant->db_username);
        $this->assertNull($tenant->db_password);
        $this->assertTrue($tenant->db_master_access, 'master credentials are the connection');
        $this->assertSame(TenantState::Ready, $tenant->state);
    }

    public function test_the_must_change_password_box_is_honoured(): void
    {
        $this->actingAs(CentralUser::factory()->create(), 'central');

        $this->post($this->centralUrl('/tenants'), $this->formData(['admin_must_change_password' => '0']))
            ->assertSessionHasNoErrors();

        $tenant = $this->tenant('acme');
        $this->assertFalse($tenant->run(fn () => User::query()->sole()->must_change_password));
        tenancy()->end();
        auth()->shouldUse('web'); // actingAs(..., 'central') switched the default guard

        $this->post($this->tenantUrl('acme', '/login'), ['email' => 'alice@acme.test', 'password' => 'Secret123!']);
        $this->get($this->tenantUrl('acme', '/dashboard'))->assertOk();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Ltd',
            'subdomain' => 'acme',
            'admin_name' => 'Alice',
            'admin_email' => 'alice@acme.test',
            'admin_password' => 'Secret123!',
            'admin_password_confirmation' => 'Secret123!',
            'admin_must_change_password' => '1',
            'dedicated_db_user' => '1',
            'db_username' => '',
            'db_password' => '',
            'db_master_access' => '1',
        ], $overrides);
    }

    /**
     * Connect as the given user and count the tenant's users (or just SELECT 1 without a database).
     */
    private function probe(string $username, string $password, ?string $database): int
    {
        Config::set('database.connections.probe', array_merge(config('database.connections.mysql'), [
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ]));
        DB::purge('probe');

        return $database === null
            ? (int) DB::connection('probe')->selectOne('SELECT 1 AS one')->one
            : DB::connection('probe')->table('users')->count();
    }

    /**
     * @return list<string>
     */
    private function masterGrants(): array
    {
        return array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW GRANTS FOR CURRENT_USER()'));
    }
}
