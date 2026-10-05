<?php

namespace Tests\Feature\Provisioning;

use App\Enums\TenantState;
use App\Jobs\Teardown\DropTenantDatabase;
use App\Models\Tenant;
use App\Services\TenantTeardown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class TeardownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_teardown_removes_resources_and_soft_deletes_the_record(): void
    {
        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);
        $tenant->run(fn () => Redis::connection('cache')->set('probe', 1));

        app(TenantTeardown::class)->teardown($tenant);

        $tenant = $this->tenant('acme');
        $this->assertTrue($tenant->trashed(), 'the row stays as the record');
        $this->assertSame(TenantState::Deleted, $tenant->state);
        $this->assertNull(Tenant::query()->where('subdomain', 'acme')->first(), 'hidden from normal queries');

        $this->assertNull(DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$tenant->db_name]));
        $this->assertNull(DB::selectOne('SELECT 1 FROM mysql.user WHERE user = ?', [$tenant->db_username]));
        $this->assertDirectoryDoesNotExist(base_path($tenant->storage_path));
        $this->assertSame(0, Redis::connection('cache')->client()->exists($tenant->cache_prefix.'probe'));

        $this->get($this->tenantUrl('acme', '/login'))->assertNotFound()->assertSee('No such workspace');
    }

    public function test_teardown_without_a_dedicated_user_drops_no_mysql_user(): void
    {
        $tenant = $this->createTenant('acme');
        $usersBefore = DB::table('mysql.user')->count();

        app(TenantTeardown::class)->teardown($tenant);

        $this->assertSame(TenantState::Deleted, $this->tenant('acme')->state);
        $this->assertSame($usersBefore, DB::table('mysql.user')->count());
    }

    public function test_drop_jobs_refuse_names_that_are_not_exactly_the_tenants_own(): void
    {
        $tenant = $this->createTenant('acme');

        // The landlord test database starts with the same project prefix; the guard must not
        // accept it just because the prefix matches.
        DB::table('tenants')->where('id', $tenant->id)->update(['db_name' => 'tenancey_testing']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to touch tenant [{$tenant->uuid}]");

        try {
            (new DropTenantDatabase($tenant->id))->handle();
        } finally {
            $this->assertNotNull(DB::selectOne('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', ['tenancey_testing']));
            DB::table('tenants')->where('id', $tenant->id)->update(['db_name' => 'tenanceytest_'.$tenant->uuid]);
        }
    }
}
