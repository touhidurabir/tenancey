<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Append-only record of what central admins did. Lives in the landlord DB (CentralConnection:
 * impersonation is audited while a tenant is initialized). Every entry is mirrored to the
 * `tenancy` log channel.
 *
 * @property int $id
 * @property AuditAction $action
 * @property int|null $actor_id
 * @property string|null $actor_email
 * @property int|null $tenant_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    /**
     * @param  array{type: string, id: int|string, label?: ?string}|null  $subject
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        AuditAction $action,
        ?CentralUser $actor = null,
        ?Tenant $tenant = null,
        ?array $subject = null,
        array $metadata = [],
    ): self {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $entry = static::query()->create([
            'action' => $action,
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'tenant_id' => $tenant?->id,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => isset($subject['id']) ? (string) $subject['id'] : null,
            'subject_label' => $subject['label'] ?? null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'metadata' => $metadata ?: null,
        ]);

        Log::channel('tenancy')->log($action->isWarning() ? 'warning' : 'notice', 'Audit: '.$action->label(), [
            'audit_id' => $entry->id,
            'action' => $action->value,
            'actor' => $actor?->email,
            ...($tenant ? $tenant->logContext() : []),
            'subject' => $entry->subject_label ?? $entry->subject_id,
            'ip' => $entry->ip_address,
            ...$metadata,
        ]);

        return $entry;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }
}
