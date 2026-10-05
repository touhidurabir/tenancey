<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant routes: ends an impersonation whose time is up, and shares the marker with the views
 * (the red banner in the tenant layout).
 */
class HandleImpersonation
{
    public function __construct(protected Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->isExpired($request)) {
            return redirect()->away($this->impersonation->end($request, AuditAction::ImpersonationExpired));
        }

        View::share('impersonator', $this->impersonation->current($request));

        return $next($request);
    }
}
