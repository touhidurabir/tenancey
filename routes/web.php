<?php

use App\Http\Controllers\Central\AuditLogController;
use App\Http\Controllers\Central\ImpersonationController;
use App\Http\Controllers\Central\LoginController;
use App\Http\Controllers\Central\TenantController;
use Illuminate\Support\Facades\Route;

/*
| Central (landlord) routes. Bound to the central domain so they never answer on a tenant
| subdomain; tenant routes live in routes/tenant.php. Route names here start with `central.`.
*/

Route::domain(config('tenancy.central_domains')[0])->name('central.')->group(function () {
    Route::redirect('/', '/tenants')->name('home');

    Route::middleware('guest:central')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth:central')->group(function () {
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        // {tenant} binds by uuid. A deleted (soft-deleted) tenant's page stays viewable as its record.
        Route::resource('tenants', TenantController::class)->withTrashed(['show']);
        Route::post('tenants/{tenant}/toggle', [TenantController::class, 'toggle'])->name('tenants.toggle');
        Route::post('tenants/{tenant}/retry', [TenantController::class, 'retry'])->name('tenants.retry');
        Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'store'])->name('tenants.impersonate');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    });
});
