<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('central.auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate('central');
        $request->session()->regenerate();

        return redirect()->intended(route('central.tenants.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('central')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('central.login');
    }
}
