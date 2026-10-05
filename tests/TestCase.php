<?php

namespace Tests;

use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Tests run against real MySQL (provisioning creates databases and users), so:
 *  - the landlord database must end in `_testing`, and tenant resources use test-only prefixes
 *    (see phpunit.xml), checked before anything touches the database;
 *  - DatabaseTruncation instead of RefreshDatabase: CREATE DATABASE implicitly commits, which
 *    would break RefreshDatabase's wrapping transaction;
 *  - the `provisioning` queue connection runs synchronously, so a chain completes inside the test.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.mysql.database');
        $tenantPrefix = $app['config']->get('tenancy.database.prefix');

        if (! str_ends_with((string) $database, '_testing') || $tenantPrefix !== 'tenanceytest_') {
            throw new RuntimeException("Refusing to run tests against [{$database}] with tenant prefix [{$tenantPrefix}]. Run them through phpunit.xml.");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.connections.provisioning', ['driver' => 'sync']);
        // Keep test runs out of the real storage/logs/tenancy-*.log (TenancyLoggingTest swaps in its own file).
        config()->set('logging.channels.tenancy', config('logging.channels.null'));

        $this->dropTestTenantResources();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->dropTestTenantResources();

        parent::tearDown();
    }

    /**
     * Provision a tenant through the real chain (synchronously). $dedicatedDatabaseUser gives it
     * its own database user with generated credentials; pass `db_username`/`db_password` in
     * $overrides to choose them (usernames must start with `tx_` so cleanup finds them).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createTenant(string $subdomain = 'acme', array $overrides = [], bool $dedicatedDatabaseUser = false): Tenant
    {
        app(TenantProvisioner::class)->provision(array_merge([
            'name' => ucfirst($subdomain).' Ltd',
            'subdomain' => $subdomain,
            'admin_name' => 'Alice',
            'admin_email' => "alice@{$subdomain}.test",
            'admin_phone' => '0123456789',
            'admin_password' => 'Secret123!',
            'dedicated_db_user' => $dedicatedDatabaseUser,
        ], $overrides));

        return $this->tenant($subdomain);
    }

    /**
     * Fresh copy of a tenant by subdomain, including soft-deleted ones.
     */
    protected function tenant(string $subdomain): Tenant
    {
        return Tenant::withTrashed()->where('subdomain', $subdomain)->firstOrFail();
    }

    protected function tenantUrl(string $subdomain, string $path = '/'): string
    {
        return 'http://'.$subdomain.'.'.config('tenancy.central_domains')[0].'/'.ltrim($path, '/');
    }

    protected function centralUrl(string $path = '/'): string
    {
        return 'http://'.config('tenancy.central_domains')[0].'/'.ltrim($path, '/');
    }

    /**
     * Drop every database, MySQL user and storage folder carrying the TEST-ONLY tenant prefixes.
     * Runs before and after each test, so a killed run cannot poison the next one.
     */
    protected function dropTestTenantResources(): void
    {
        $central = DB::connection(config('tenancy.database.central_connection'));

        $databases = $central->select(
            'SELECT schema_name AS name FROM information_schema.schemata WHERE schema_name LIKE ?',
            [str_replace('_', '\_', config('tenancy.database.prefix')).'%'],
        );
        foreach ($databases as $database) {
            $central->statement("DROP DATABASE IF EXISTS `{$database->name}`");
        }

        $users = $central->select(
            'SELECT user AS name, host FROM mysql.user WHERE user LIKE ?',
            [str_replace('_', '\_', config('tenancy.database.user_prefix')).'%'],
        );
        foreach ($users as $user) {
            $central->statement("DROP USER IF EXISTS `{$user->name}`@`{$user->host}`");
        }

        foreach (File::directories(storage_path()) as $directory) {
            if (str_starts_with(basename($directory), config('tenancy.filesystem.suffix_base'))) {
                File::deleteDirectory($directory);
            }
        }
    }
}
