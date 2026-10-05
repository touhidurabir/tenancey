<?php

namespace App\Tenancy\Database;

/**
 * Creates and drops a tenant's dedicated database user. One implementation per database driver
 * (config `tenancy.database.user_managers`), so moving to Postgres means adding a class, not
 * touching the provisioning jobs.
 *
 * Implementations must quote every value themselves: usernames and passwords can be typed in by
 * an admin. They never touch any other user's privileges (the master .env user included).
 */
interface DatabaseUserManager
{
    public function userExists(string $username): bool;

    /**
     * Create the user and give it full rights on $database only.
     */
    public function createUser(string $username, string $password, string $database): void;

    public function dropUser(string $username): void;
}
