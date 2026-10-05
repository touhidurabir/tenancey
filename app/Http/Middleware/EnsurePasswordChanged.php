<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users created with a temporary password (the emailed one) must choose their own first.
 * Not while a central admin is impersonating them: the password is the user's to choose.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && $request->session()->missing(Impersonation::SESSION_KEY)) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
