<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('tenant.auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate('web');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, Impersonation $impersonation): RedirectResponse
    {
        // Logging out while impersonating ends the impersonation (audited) and returns to central.
        if ($impersonation->current($request) !== null) {
            return redirect()->away($impersonation->end($request));
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
