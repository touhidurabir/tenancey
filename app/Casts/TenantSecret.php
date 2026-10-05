<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Encrypts a tenant secret (the dedicated database password) with `tenancy.database.secret_key`
 * (TENANT_DB_KEY), falling back to APP_KEY when that is empty.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class TenantSecret implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : static::encrypter()->decryptString($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : static::encrypter()->encryptString((string) $value);
    }

    public static function encrypter(): Encrypter
    {
        $key = config('tenancy.database.secret_key') ?: config('app.key');

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(Str::after($key, 'base64:'));
        }

        return new Encrypter($key, config('app.cipher'));
    }
}
