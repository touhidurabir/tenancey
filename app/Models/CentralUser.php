<?php

namespace App\Models;

use Database\Factories\CentralUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A landlord admin who manages tenants. Always reads the landlord `users` table, even while
 * tenancy is initialized (the `central` guard).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class CentralUser extends Authenticatable
{
    /** @use HasFactory<CentralUserFactory> */
    use CentralConnection, HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
