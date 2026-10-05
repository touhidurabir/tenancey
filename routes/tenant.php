<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\ImpersonationController;
use App\Http\Controllers\Tenant\LoginController;
use App\Http\Controllers\Tenant\PasswordController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\HandleImpersonation;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
| Tenant routes: {subdomain}.tenancey.test. Loaded by App\Providers\TenancyServiceProvider.
| The tenancy middleware is given top priority there, so the tenant is identified before the
| session starts and the session is read from that tenant's Redis prefix.
*/

Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsActive::class,
    HandleImpersonation::class,
])->group(function () {
    Route::redirect('/', '/dashboard');

    // One-time link from the central admin (App\Services\Impersonation).
    Route::get('impersonate/{token}', [ImpersonationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('impersonate');

    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store']);
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
        Route::post('impersonate/end', [ImpersonationController::class, 'destroy'])->name('impersonate.end');

        Route::get('password/change', [PasswordController::class, 'edit'])->name('password.change');
        Route::put('password/change', [PasswordController::class, 'update']);

        Route::get('dashboard', DashboardController::class)
            ->middleware(EnsurePasswordChanged::class)
            ->name('dashboard');
    });
});
