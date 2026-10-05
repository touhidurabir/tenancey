<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CentralUser;
use App\Models\ImpersonationToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exceptions\ImpersonationRefused;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A central admin signing in to a tenant as that tenant's first admin.
 *
 * 1. issue()   central domain: checks the tenant, finds its first admin, stores a hashed
 *              one-time token (60 s) and returns the link to the tenant's subdomain.
 * 2. consume() tenant subdomain: claims the token atomically, signs in on the `web` guard and
 *              marks the session with who is impersonating, until when.
 * 3. end()     "End impersonation", logging out, or the session limit running out.
 *
 * Every step, refusals included, is written to audit_logs (and the `tenancy` log channel).
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator';

    /**
     * @throws ImpersonationRefused
     */
    public function issue(CentralUser $admin, Tenant $tenant): string
    {
        if (! $tenant->isAccessible()) {
            $this->refuse($admin, $tenant, 'The tenant must be enabled and ready.');
        }

        $user = $this->firstAdmin($tenant)
            ?? $this->refuse($admin, $tenant, 'The tenant has no user with the admin role.');

        $token = Str::random(64);

        ImpersonationToken::query()->create([
            'token_hash' => ImpersonationToken::hash($token),
            'central_user_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds(config('tenancy.impersonation.token_ttl')),
        ]);

        AuditLog::record(AuditAction::ImpersonationRequested, $admin, $tenant, $this->subject($user), [
            'link_valid_seconds' => config('tenancy.impersonation.token_ttl'),
        ]);

        return $tenant->url('/impersonate/'.$token);
    }

    /**
     * Sign in with a one-time token. Runs on the tenant's subdomain (tenant initialized).
     * Returns null, after auditing why, when the token cannot be used.
     */
    public function consume(string $token, Request $request): ?User
    {
        /** @var Tenant $tenant */
        $tenant = tenant();
        $hash = ImpersonationToken::hash($token);

        // Single UPDATE: of two simultaneous requests with the same token, only one wins.
        $claimed = ImpersonationToken::query()
            ->where('token_hash', $hash)
            ->where('tenant_id', $tenant->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'used_ip' => $request->ip()]);

        $row = ImpersonationToken::query()->where('token_hash', $hash)->first();
        $admin = $row ? CentralUser::query()->find($row->central_user_id) : null;

        if (! $claimed) {
            $reason = match (true) {
                $row === null => 'unknown link',
                $row->tenant_id !== $tenant->id => 'link issued for another tenant',
                $row->used_at !== null => 'link already used',
                default => 'link expired',
            };
            AuditLog::record(AuditAction::ImpersonationRejected, $admin, $tenant, null, ['reason' => $reason]);

            return null;
        }

        $user = User::query()->find($row->user_id);

        if ($admin === null || $user === null || ! $user->hasRole('admin')) {
            AuditLog::record(AuditAction::ImpersonationRejected, $admin, $tenant, $user ? $this->subject($user) : null, [
                'reason' => $admin === null ? 'central admin no longer exists' : 'tenant user is gone or no longer an admin',
            ]);

            return null;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $startedAt = now();
        $expiresAt = $startedAt->copy()->addSeconds(config('tenancy.impersonation.session_ttl'));

        $request->session()->put(self::SESSION_KEY, [
            'central_user_id' => $admin->id,
            'central_user_email' => $admin->email,
            'token_id' => $row->id,
            'started_at' => $startedAt->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
        ]);

        AuditLog::record(AuditAction::ImpersonationStarted, $admin, $tenant, $this->subject($user), [
            'token_id' => $row->id,
            'session_expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $user;
    }

    /**
     * The session's impersonation marker, or null for a normal sign-in.
     *
     * @return array{central_user_id: int, central_user_email: string, token_id: int, started_at: int, expires_at: int}|null
     */
    public function current(Request $request): ?array
    {
        return $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
    }

    public function isExpired(Request $request): bool
    {
        $current = $this->current($request);

        return $current !== null && now()->getTimestamp() >= $current['expires_at'];
    }

    /**
     * Stop impersonating: audit, sign out, fresh session. Returns the tenant's page in the
     * central admin, where the admin came from.
     */
    public function end(Request $request, AuditAction $action = AuditAction::ImpersonationEnded): string
    {
        /** @var Tenant $tenant */
        $tenant = tenant();
        $current = $this->current($request);
        $user = $request->user('web');

        if ($current !== null) {
            AuditLog::record(
                $action,
                CentralUser::query()->find($current['central_user_id']),
                $tenant,
                $user instanceof User ? $this->subject($user) : null,
                [
                    'token_id' => $current['token_id'],
                    'duration_seconds' => now()->getTimestamp() - $current['started_at'],
                ],
            );
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return route('central.tenants.show', $tenant);
    }

    /**
     * The tenant user with the admin role and the lowest id.
     */
    public function firstAdmin(Tenant $tenant): ?User
    {
        tenancy()->initialize($tenant);

        try {
            return User::role('admin')->orderBy('id')->first();
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @throws ImpersonationRefused
     */
    protected function refuse(CentralUser $admin, Tenant $tenant, string $reason): never
    {
        AuditLog::record(AuditAction::ImpersonationRefused, $admin, $tenant, null, ['reason' => $reason]);

        throw new ImpersonationRefused($reason);
    }

    /**
     * @return array{type: string, id: int, label: string}
     */
    protected function subject(User $user): array
    {
        return ['type' => 'tenant_user', 'id' => $user->id, 'label' => $user->email];
    }
}
