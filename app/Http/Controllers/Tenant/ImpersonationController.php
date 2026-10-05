<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Arrive from the central admin with a one-time link.
     */
    public function store(Request $request, string $token, Impersonation $impersonation): RedirectResponse
    {
        abort_if($impersonation->consume($token, $request) === null, 403, 'This sign-in link is invalid, expired or already used.');

        return redirect()->route('dashboard');
    }

    /**
     * End impersonation and return to the tenant's page in the central admin.
     */
    public function destroy(Request $request, Impersonation $impersonation): RedirectResponse
    {
        return redirect()->away($impersonation->end($request));
    }
}
