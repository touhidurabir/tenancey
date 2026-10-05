<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\Database\MySqlDatabaseUserManager;
use App\Tenancy\SpatiePermissionsBootstrapper;
use App\Tenancy\TenantRedisBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => null, // `id` auto-increments; the `uuid` tenant key is set in App\Models\Tenant::booted()

    'domain_model' => Domain::class,

    /**
     * The list of domains hosting your central app.
     *
     * Only relevant if you're using the domain or subdomain identification middleware.
     */
    'central_domains' => [
        env('CENTRAL_DOMAIN', 'tenancey.test'),
    ],

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        // Replaces stancl's RedisTenancyBootstrapper: prefixes the `default` and `cache` Redis
        // connections (sessions + cache) with the tenant's own `cache_prefix` column.
        TenantRedisBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        SpatiePermissionsBootstrapper::class,
        // Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'central'),

        /**
         * Connection used as a "template" for the dynamically created tenant database connection.
         * Note: don't name your template connection tenant. That name is reserved by package.
         */
        'template_tenant_connection' => null,

        /**
         * Tenant database names are created like this:
         * prefix + tenant_id + suffix.
         */
        // Project-specific: this MySQL server also hosts other apps' `tenant_*` databases.
        'prefix' => env('TENANT_DATABASE_PREFIX', 'tenancey_'), // tenancey_{uuid}

        // Optional dedicated database user per tenant (create form). Generated names are this
        // prefix + random lowercase letters/digits, 16 characters in total. Empty in production
        // (pure random); the tests set `tx_` so their cleanup can find the users they made.
        'user_prefix' => env('TENANT_DB_USER_PREFIX', ''),
        'suffix' => '',

        /**
         * Key for the `db_password` column (App\Casts\TenantSecret), as `base64:...` like APP_KEY.
         * Empty = APP_KEY. A separate key means rotating APP_KEY cannot cut the app off from
         * every tenant database.
         */
        'secret_key' => env('TENANT_DB_KEY'),

        /**
         * Create/drop a tenant's dedicated database user, per driver of the template connection
         * (App\Tenancy\Database\DatabaseUserManager). Add pgsql here when moving to Postgres.
         */
        'user_managers' => [
            'mysql' => MySqlDatabaseUserManager::class,
            'mariadb' => MySqlDatabaseUserManager::class,
        ],

        /**
         * TenantDatabaseManagers are classes that handle the creation & deletion of tenant databases.
         */
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            // Plain manager: creates and drops DATABASES only. Do NOT use
            // PermissionControlledMySQLDatabaseManager here: its createDatabase()/deleteDatabase()
            // also CREATE/DROP a MySQL user, and with no username that is CREATE USER ''@'%':
            // an anonymous, passwordless user with ALL on the tenant DB. Optional per-tenant users
            // are handled by App\Tenancy\Database\DatabaseUserManager (`user_managers` above).
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
            'pgsql' => PostgreSQLDatabaseManager::class,

        /**
         * Use this database manager for MySQL to have a DB user created for each tenant database.
         * You can customize the grants given to these users by changing the $grants property.
         */
            // 'mysql' => Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager::class,

        /**
         * Disable the pgsql manager above, and enable the one below if you
         * want to separate tenant DBs by schemas rather than databases.
         */
            // 'pgsql' => Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager::class, // Separate by schema instead of database
        ],
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper.
     */
    'filesystem' => [
        /**
         * Each disk listed in the 'disks' array will be suffixed by the suffix_base, followed by the tenant_id.
         */
        'suffix_base' => env('TENANT_STORAGE_PREFIX', 'tenant_'), // storage/tenant_{id}
        'disks' => [
            'local',
            'public',
            // 's3',
        ],

        /**
         * Use this for local disks.
         *
         * See https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper
         */
        'root_override' => [
            // Disks whose roots should be overridden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /**
         * Should storage_path() be suffixed.
         *
         * Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
         *
         * For the vast majority of applications, this feature should be enabled. But in some
         * edge cases, it can cause issues (like using Passport with Vapor - see #196), so
         * you may want to disable this if you are experiencing these edge case issues.
         */
        'suffix_storage_path' => true,

        /**
         * By default, asset() calls are made multi-tenant too. You can use global_asset() and mix()
         * for global, non-tenant-specific assets. However, you might have some issues when using
         * packages that use asset() calls inside the tenant app. To avoid such issues, you can
         * disable asset() helper tenancy and explicitly use tenant_asset() calls in places
         * where you want to use tenant-specific assets (product images, avatars, etc).
         */
        // Keep asset() central so Vite's /build assets load on tenant subdomains.
        'asset_helper_tenancy' => false,
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBootstrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix_base' => 'tenant', // Each key in Redis will be prepended by this prefix_base, followed by the tenant id.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            'default', // sessions
            'cache',
        ],
    ],

    /**
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\UniversalRoutes::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancyforlaravel.com/docs/v3/features/tenant-config
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancyforlaravel.com/docs/v3/features/cross-domain-redirect
        // Stancl\Tenancy\Features\ViteBundler::class,
    ],

    /**
     * Should tenancy routes be registered.
     *
     * Tenancy routes include tenant asset routes. By default, this route is
     * enabled. But it may be useful to disable them if you use external
     * storage (e.g. S3 / Dropbox) or have a custom asset controller.
     */
    'routes' => false, // only serves tenant assets, which we don't use

    /**
     * Parameters used by the tenants:migrate command.
     */
    'migration_parameters' => [
        '--force' => true, // This needs to be true to run migrations in production.
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\TenantDatabaseSeeder', // root seeder class
        // '--force' => true, // This needs to be true to seed tenant databases in production
    ],

    /**
     * Central admins signing in to a tenant as its first admin (App\Services\Impersonation).
     * Not stancl's UserImpersonation feature: ours hashes tokens, records the admin and audits.
     */
    'impersonation' => [
        'token_ttl' => 60, // seconds the one-time sign-in link stays valid
        'session_ttl' => (int) env('IMPERSONATION_SESSION_MINUTES', 60) * 60, // seconds
    ],
];
