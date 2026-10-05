<?php

namespace App\Tenancy\Database;

use App\Models\Tenant;
use App\Tenancy\TenantResourceGuard;
use RuntimeException;

/**
 * Generated credentials for a tenant's dedicated database user, used when the admin leaves the
 * create form's username or password empty.
 */
class DatabaseCredentials
{
    public const USERNAME_LENGTH = 16;

    public const PASSWORD_MIN_LENGTH = 12;

    public const PASSWORD_MAX_LENGTH = 24;

    /**
     * `tenancy.database.user_prefix` (empty in production) + random lowercase letters and digits,
     * 16 characters in total, starting with a letter. Unused by any tenant (deleted ones
     * included) and not an existing user on the database server.
     */
    public static function generateUsername(DatabaseUserManager $manager): string
    {
        $prefix = (string) config('tenancy.database.user_prefix');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $username = $prefix.static::randomFrom('abcdefghijklmnopqrstuvwxyz', 1);
            $username .= static::randomFrom('abcdefghijklmnopqrstuvwxyz0123456789', self::USERNAME_LENGTH - strlen($username));

            if (! Tenant::withTrashed()->where('db_username', $username)->exists() && ! $manager->userExists($username)) {
                TenantResourceGuard::assertUsernameAllowed($username);

                return $username;
            }
        }

        throw new RuntimeException('Could not generate an unused database username.');
    }

    /**
     * 12 to 24 characters with at least one lowercase, uppercase, digit and symbol. The symbols
     * avoid quotes and backslashes so the password is easy to copy into a client.
     */
    public static function generatePassword(): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#%^*-_=+.:'];
        $length = random_int(self::PASSWORD_MIN_LENGTH, self::PASSWORD_MAX_LENGTH);

        $characters = array_map(fn (string $set) => static::randomFrom($set, 1), $sets);
        $characters = array_merge($characters, str_split(static::randomFrom(implode('', $sets), $length - count($sets))));

        for ($i = count($characters) - 1; $i > 0; $i--) { // Fisher–Yates with a CSPRNG
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    protected static function randomFrom(string $alphabet, int $length): string
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $result;
    }
}
