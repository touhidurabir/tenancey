<?php

namespace Tests\Feature\Provisioning;

use App\Services\TenantTeardown;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Provisioning and teardown write every step, tagged with the tenant and run, to the `tenancy`
 * channel (searched by tenant uuid in /log-viewer).
 */
class TenancyLoggingTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->logFile = storage_path('logs/tenancy-test.log');
        File::delete($this->logFile);
        Config::set('logging.channels.tenancy', ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug']);
    }

    protected function tearDown(): void
    {
        File::delete($this->logFile);

        parent::tearDown();
    }

    public function test_every_provisioning_step_is_logged_with_the_tenant_and_run(): void
    {
        $tenant = $this->createTenant('acme', dedicatedDatabaseUser: true);
        $lines = $this->lines();

        $this->assertStringContainsString('Provisioning run started (provision)', $lines[0]);
        foreach (['Create database', 'Create database user', 'Run migrations', 'Seed database', 'Prepare storage', 'Create first user', 'Mark tenant ready', 'Send credentials email'] as $step) {
            $this->assertNotEmpty(preg_grep('/\['.preg_quote($step, '/').'\] Step finished/', $lines), "missing [{$step}]");
        }
        $this->assertNotEmpty(preg_grep('/Provisioning run finished/', $lines));

        // Every line carries the tenant uuid and the same run id (Context travels with the chain).
        preg_match('/"tenancy_run":"([0-9A-Z]{26})"/', $lines[0], $run);
        $this->assertNotEmpty($run);
        foreach ($lines as $line) {
            $this->assertStringContainsString($tenant->uuid, $line);
            $this->assertStringContainsString($run[1], $line);
        }

        // No secrets, and nothing written inside the tenant's own storage.
        $this->assertStringNotContainsString('Secret123!', implode("\n", $lines));
        $this->assertStringNotContainsString($tenant->db_password, implode("\n", $lines));
        $this->assertDirectoryDoesNotExist(base_path($tenant->storage_path.'/logs/tenancy-test.log'));
    }

    public function test_a_failing_step_is_logged_with_its_exception(): void
    {
        Config::set('tenancy.seeder_parameters.--class', 'Database\\Seeders\\MissingSeeder');

        try {
            $this->createTenant('acme'); // the sync queue rethrows
        } catch (BindingResolutionException) {
        }

        $tenant = $this->tenant('acme');
        $log = implode("\n", $this->lines());

        $this->assertStringContainsString('[Seed database] Step failed', $log);
        $this->assertStringContainsString('"will_retry":', $log);
        $this->assertStringContainsString('Provisioning run failed at [Seed database]', $log);
        $this->assertStringContainsString($tenant->uuid, $log);
    }

    public function test_teardown_is_logged_as_its_own_run(): void
    {
        $tenant = $this->createTenant('acme');
        File::delete($this->logFile);

        app(TenantTeardown::class)->teardown($tenant);
        $log = implode("\n", $this->lines());

        $this->assertStringContainsString('Teardown run started', $log);
        $this->assertStringContainsString('"tenancy_operation":"teardown"', $log);
        $this->assertStringContainsString('[Drop database user] Skipped: no dedicated database user', $log);
        $this->assertStringContainsString('Teardown run finished', $log);
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return array_values(array_filter(explode("\n", File::get($this->logFile))));
    }
}
