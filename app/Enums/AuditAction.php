<?php

namespace App\Enums;

/**
 * What a central admin did (or tried to do), as stored in audit_logs.action.
 */
enum AuditAction: string
{
    case CentralLogin = 'central.login';
    case CentralLoginFailed = 'central.login_failed';
    case CentralLogout = 'central.logout';

    case TenantCreated = 'tenant.created';
    case TenantRenamed = 'tenant.renamed';
    case TenantEnabled = 'tenant.enabled';
    case TenantDisabled = 'tenant.disabled';
    case TenantRetried = 'tenant.retried';
    case TenantDeleteRequested = 'tenant.delete_requested';

    case ImpersonationRequested = 'impersonation.requested';
    case ImpersonationRefused = 'impersonation.refused';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationRejected = 'impersonation.rejected';
    case ImpersonationEnded = 'impersonation.ended';
    case ImpersonationExpired = 'impersonation.expired';

    public function label(): string
    {
        return match ($this) {
            self::CentralLogin => 'Admin signed in',
            self::CentralLoginFailed => 'Admin sign-in failed',
            self::CentralLogout => 'Admin signed out',
            self::TenantCreated => 'Tenant created',
            self::TenantRenamed => 'Tenant renamed',
            self::TenantEnabled => 'Tenant enabled',
            self::TenantDisabled => 'Tenant disabled',
            self::TenantRetried => 'Provisioning retried',
            self::TenantDeleteRequested => 'Tenant delete requested',
            self::ImpersonationRequested => 'Impersonation link issued',
            self::ImpersonationRefused => 'Impersonation refused',
            self::ImpersonationStarted => 'Impersonation started',
            self::ImpersonationRejected => 'Impersonation link rejected',
            self::ImpersonationEnded => 'Impersonation ended',
            self::ImpersonationExpired => 'Impersonation expired',
        };
    }

    /**
     * Something denied or failed, highlighted in the audit list.
     */
    public function isWarning(): bool
    {
        return in_array($this, [self::CentralLoginFailed, self::ImpersonationRefused, self::ImpersonationRejected], true);
    }
}
