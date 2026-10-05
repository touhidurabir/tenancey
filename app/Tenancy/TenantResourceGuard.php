<?php

namespace App\Tenancy;

use App\Models\Tenant;
use RuntimeException;

/**
 * Last line of defence before creating or destroying a tenant's database, database user or
 * storage folder. This database server and storage directory are shared with other apps (and
 * with this app's own `tenancey` and `tenancey_testing` databases), so names must match EXACTLY
 * what this tenant was given, not merely start with a prefix.
 *
 * A dedicated database user can be named by the admin, so its name cannot be derived. Instead it
 * must be a valid, non-reserved name, and it may only be dropped or recreated once
 * `db_user_created_at` proves provisioning created it.
 */
class TenantResourceGuard
{
    /**
     * Letters, digits, underscore; starts with a letter; 32 characters at most (MySQL's limit).
     */
    public const USERNAME_PATTERN = '/^[a-z][a-z0-9_]{2,31}$/';

    public static function assertOwned(Tenant $tenant): void
    {
        $expected = [
            'db_name' => static::databaseNameFor($tenant->uuid),
            'storage_path' => static::storagePathFor($tenant->uuid),
        ];

        foreach ($expected as $column => $value) {
            if ($tenant->{$column} !== $value) {
                throw new RuntimeException("Refusing to touch tenant [{$tenant->uuid}]: {$column} is [{$tenant->{$column}}], expected [{$value}].");
            }
        }

        if ($tenant->hasDedicatedDatabaseUser()) {
            static::assertUsernameAllowed($tenant->db_username);
        }
    }

    /**
     * Before dropping or recreating an EXISTING database user: provisioning must have created it.
     */
    public static function assertDatabaseUserCreatedByUs(Tenant $tenant): void
    {
        static::assertOwned($tenant);

        if ($tenant->db_user_created_at === null) {
            throw new RuntimeException("Refusing to touch database user [{$tenant->db_username}]: it was not created by tenant [{$tenant->uuid}].");
        }
    }

    public static function assertUsernameAllowed(?string $username): void
    {
        if (! is_string($username) || ! preg_match(self::USERNAME_PATTERN, $username) || static::isReservedUsername($username)) {
            throw new RuntimeException("Refusing database username [{$username}].");
        }
    }

    /**
     * Names a tenant must never get: server accounts and the master .env user.
     */
    public static function isReservedUsername(string $username): bool
    {
        $central = config('database.connections.'.config('tenancy.database.central_connection'));

        return in_array(strtolower($username), array_map('strtolower', array_filter([
            'root', 'admin', 'mysql', 'postgres', 'debian_sys_maint', 'sys', 'forge',
            $central['username'] ?? null,
        ])), true);
    }

    public static function databaseNameFor(string $uuid): string
    {
        return config('tenancy.database.prefix').$uuid;
    }

    /**
     * The tenant's storage folder relative to the project root, matching what stancl's
     * FilesystemTenancyBootstrapper uses (storage_path() + suffix_base + tenant key).
     */
    public static function storagePathFor(string $uuid): string
    {
        return 'storage/'.config('tenancy.filesystem.suffix_base').$uuid;
    }
}
