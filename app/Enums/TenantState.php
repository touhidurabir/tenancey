<?php

namespace App\Enums;

/**
 * Where a tenant is in its lifecycle. Whether an admin has switched it off is a separate
 * concern: the `enabled` column.
 */
enum TenantState: int
{
    case Provisioning = 1;
    case Ready = 2;
    case Failed = 3;
    case Deleting = 4;
    case Deleted = 5;

    public function label(): string
    {
        return $this->name;
    }

    /**
     * What this state means, for the admin UI.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Provisioning => 'The provisioning chain is creating the database, storage and first user.',
            self::Ready => 'Fully provisioned. Users can sign in while the tenant is enabled.',
            self::Failed => 'A provisioning step failed after its retries. Fix the cause and retry.',
            self::Deleting => 'The teardown chain is removing the database, storage and cache.',
            self::Deleted => 'Resources removed. The record is kept (soft deleted) and the subdomain stays reserved.',
        };
    }

    /**
     * Tailwind classes for the state badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Ready => 'bg-green-100 text-green-800',
            self::Provisioning => 'bg-blue-100 text-blue-800',
            self::Failed => 'bg-red-100 text-red-800',
            self::Deleting => 'bg-amber-100 text-amber-800',
            self::Deleted => 'bg-gray-200 text-gray-600',
        };
    }

    /**
     * A chain is running for this tenant.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Provisioning, self::Deleting], true);
    }
}
