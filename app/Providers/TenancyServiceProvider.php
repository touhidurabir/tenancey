<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\Database\DatabaseUserManager;
use App\Tenancy\SubdomainTenantResolver;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;
use Stancl\Tenancy\Resolvers;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            // Provisioning is NOT driven by this event: App\Services\TenantProvisioner saves the tenant
            // with events muted and dispatches its own Bus::chain (App\Jobs\Provisioning).
            Events\TenantCreated::class => [],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            // Teardown is NOT driven by this event either: see App\Services\TenantTeardown.
            Events\TenantDeleted::class => [],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register()
    {
        // Tenants are looked up by tenants.subdomain; there is no stancl `domains` table.
        $this->app->singleton(Resolvers\DomainTenantResolver::class, SubdomainTenantResolver::class);

        // Dedicated tenant database users, on the server that hosts the tenant databases.
        $this->app->bind(DatabaseUserManager::class, function () {
            $connection = DB::connection(config('tenancy.database.template_tenant_connection')
                ?? config('tenancy.database.central_connection'));
            $manager = config('tenancy.database.user_managers')[$connection->getDriverName()]
                ?? throw new RuntimeException("No tenant database user manager for driver [{$connection->getDriverName()}].");

            return new $manager($connection);
        });
    }

    public function boot()
    {
        $this->bootEvents();
        $this->handleUnknownSubdomains();
        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    /**
     * An unknown subdomain gets a "no such workspace" page instead of an exception. Any other
     * identification failure (e.g. the central domain hitting a tenant-only route) is a 404.
     */
    protected function handleUnknownSubdomains(): void
    {
        Middleware\InitializeTenancyBySubdomain::$onFail = function (\Exception $e) {
            if ($e instanceof TenantCouldNotBeIdentifiedOnDomainException) {
                return response()->view('tenant.unknown', status: 404);
            }

            abort(404);
        };
    }

    protected function mapRoutes()
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority()
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
