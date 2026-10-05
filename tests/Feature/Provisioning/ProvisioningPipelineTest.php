<?php

namespace Tests\Feature\Provisioning;

use App\Enums\TenantState;
use App\Mail\TenantCredentialsMail;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProvisioningPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_the_chain_provisions_a_complete_tenant(): void
    {
        $tenant = $this->createTenant('acme');

        $this->assertSame(TenantState::Ready, $tenant->state);
        $this->assertTrue($tenant->isAccessible());
        $this->assertNotNull($tenant->provisioned_at);
        $this->assertNull($tenant->pending_admin, 'the plaintext password must be gone');
        $this->assertNull($tenant->current_step);
        $this->assertNull($tenant->last_error);

        $this->assertNotNull($this->schema($tenant->db_name));
        $this->assertDirectoryExists(base_path($tenant->storage_path.'/app/public'));

        // The first user: hashed temporary password, the `admin` role.
        $user = $tenant->run(fn () => User::query()->sole());
        $this->assertSame('alice@acme.test', $user->email);
        $this->assertSame('0123456789', $user->phone);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Secret123!', $user->password));
        $this->assertSame(['admin'], $tenant->run(fn () => $user->getRoleNames()->all()));
        $this->assertSame(['admin', 'member'], $tenant->run(fn () => DB::table('roles')->orderBy('name')->pluck('name')->all()));

        Mail::assertSent(TenantCredentialsMail::class, function (TenantCredentialsMail $mail) {
            return $mail->hasTo('alice@acme.test')
                && $mail->admin['password'] === 'Secret123!'
                && str_contains($mail->render(), 'http://acme.tenancey.test/login');
        });
    }

    public function test_without_a_dedicated_user_no_mysql_user_is_created(): void
    {
        // Regression: stancl's PermissionControlledMySQLDatabaseManager::createDatabase() also ran
        // CREATE USER with the (null) username, creating MySQL's anonymous ''@'%' user with ALL on
        // the tenant database and no password.
        $usersBefore = $this->mysqlUsers();

        $tenant = $this->createTenant('acme');

        $this->assertSame(TenantState::Ready, $tenant->state);
        $this->assertSame($usersBefore, $this->mysqlUsers());
        $this->assertSame(config('database.connections.mysql.username'), $tenant->run(fn () => DB::connection()->getConfig('username')));
    }

    public function test_a_dedicated_user_reaches_its_own_database_only(): void
    {
        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);

        // Generated: the test prefix (production: none) + random, 16 characters in total.
        $this->assertMatchesRegularExpression('/^tx_[a-z][a-z0-9]{12}$/', $tenant->db_username);
        $this->assertNotNull($tenant->db_user_created_at);
        $this->assertSame([$tenant->db_name], DB::table('mysql.db')->where('User', $tenant->db_username)->pluck('Db')->all());

        Config::set('database.connections.probe', array_merge(config('database.connections.mysql'), [
            'database' => $tenant->db_name,
            'username' => $tenant->db_username,
            'password' => $tenant->db_password,
        ]));
        $this->assertSame(1, DB::connection('probe')->table('users')->count());
        $this->assertNull(rescue(fn () => DB::connection('probe')->select('SELECT 1 FROM tenancey_testing.tenants'), null, false));
        $this->assertSame($tenant->db_username, $tenant->run(fn () => DB::connection()->getConfig('username')));
    }

    public function test_every_step_is_idempotent(): void
    {
        $tenant = $this->createTenant('acme');

        foreach (TenantProvisioner::STEPS as $job) {
            (new $job($tenant->id))->handle();
        }

        $this->assertSame(1, $tenant->run(fn () => User::query()->count()));
        $this->assertSame(TenantState::Ready, $tenant->fresh()->state);
        Mail::assertSentCount(1);
    }

    public function test_tenants_get_separate_databases_storage_cache_and_permissions(): void
    {
        // Cache isolation comes from the per-tenant Redis prefix, so use the real Redis store
        // (phpunit.xml defaults to `array`, which is shared by all tenants).
        config()->set('cache.default', 'redis');
        Redis::connection('cache')->flushdb(); // test-only Redis DB (REDIS_CACHE_DB in phpunit.xml)
        $acme = $this->createTenant('acme');
        $beta = $this->createTenant('beta');

        $acme->run(fn () => cache()->put('greeting', 'hello from acme'));
        $this->assertNull($beta->run(fn () => cache()->get('greeting')));

        $this->assertSame(['alice@acme.test'], $acme->run(fn () => User::query()->pluck('email')->all()));
        $this->assertSame(['alice@beta.test'], $beta->run(fn () => User::query()->pluck('email')->all()));
        $this->assertSame(base_path($beta->storage_path), $beta->run(fn () => storage_path()));

        // A role created in one tenant (same process, same PermissionRegistrar) is invisible to the other.
        $acme->run(fn () => Role::findOrCreate('auditor', 'web'));
        $this->assertFalse($beta->run(fn () => Role::query()->where('name', 'auditor')->exists()));
    }

    /**
     * @return list<string>
     */
    private function mysqlUsers(): array
    {
        return DB::table('mysql.user')->orderBy('User')->orderBy('Host')->get(['User', 'Host'])
            ->map(fn ($row) => $row->User.'@'.$row->Host)->all();
    }

    private function schema(string $name): ?object
    {
        return DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$name]);
    }
}
