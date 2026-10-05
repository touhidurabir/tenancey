<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        $this->denyWhileImpersonating($request);

        return view('tenant.password.change');
    }

    public function update(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        return redirect()->route('dashboard')->with('status', 'Password updated.');
    }

    /**
     * A central admin signed in as this user must not change their password.
     */
    protected function denyWhileImpersonating(Request $request): void
    {
        abort_if($request->session()->has(Impersonation::SESSION_KEY), 403, 'Not available while impersonating.');
    }
}
