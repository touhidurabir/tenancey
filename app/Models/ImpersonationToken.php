<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A one-time link for a central admin to sign in to a tenant. Landlord DB (CentralConnection:
 * consumed on the tenant's subdomain, while the tenant is initialized). See App\Services\Impersonation.
 *
 * @property int $id
 * @property string $token_hash
 * @property int $central_user_id
 * @property int $tenant_id
 * @property int $user_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $used_ip
 * @property Carbon $created_at
 */
class ImpersonationToken extends Model
{
    use CentralConnection, MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Expired a day ago: kept briefly for the audit trail, which has the full story anyway.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'central_user_id' => 'integer',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
