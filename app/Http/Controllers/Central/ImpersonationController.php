<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Exceptions\ImpersonationRefused;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Send the admin to the tenant's subdomain with a one-time sign-in link.
     */
    public function store(Request $request, Tenant $tenant, Impersonation $impersonation): RedirectResponse
    {
        try {
            $url = $impersonation->issue($request->user('central'), $tenant);
        } catch (ImpersonationRefused $e) {
            return back()->with('error', 'Cannot impersonate: '.$e->getMessage());
        }

        return redirect()->away($url);
    }
}
