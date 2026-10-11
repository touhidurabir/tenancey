<?php

namespace App\Providers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CentralUser;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `npm run dev` is a one-shot build here, so `composer dev` runs the HMR server instead.
        DevCommands::node('hot', 'vite');

        $this->configureAssetBuildDirectory();
        $this->auditCentralSignIns();
        $this->configureLogViewer();
    }

    /**
     * Point Laravel's Vite helper at the same build directory and hot file that vite.config.js
     * uses for ASSET_BUILD_DIRECTORY. Unset or empty (Valet) means public/build and public/hot.
     * Always sets both, so it also resets them. Public so the test can re-run it.
     */
    public function configureAssetBuildDirectory(): void
    {
        $directory = config('app.asset_build_directory') ?: 'build';

        Vite::useBuildDirectory($directory);
        Vite::useHotFile(public_path($directory === 'build' ? 'hot' : $directory.'.hot'));
    }

    /**
     * Central admin sign-in, failed sign-in and sign-out go to the audit log. Tenant users'
     * sign-ins (the `web` guard) are not audited here.
     */
    protected function auditCentralSignIns(): void
    {
        Event::listen(function (Login $event) {
            if ($event->guard === 'central' && $event->user instanceof CentralUser) {
                AuditLog::record(AuditAction::CentralLogin, $event->user);
            }
        });

        Event::listen(function (Failed $event) {
            if ($event->guard === 'central') {
                AuditLog::record(
                    AuditAction::CentralLoginFailed,
                    $event->user instanceof CentralUser ? $event->user : null,
                    metadata: ['email' => $event->credentials['email'] ?? null],
                );
            }
        });

        Event::listen(function (Logout $event) {
            if ($event->guard === 'central' && $event->user instanceof CentralUser) {
                AuditLog::record(AuditAction::CentralLogout, $event->user);
            }
        });
    }

    /**
     * /log-viewer is for central admins only (it is also bound to the central domain and behind
     * `auth:central`, see config/log-viewer.php). Log files cannot be deleted from the UI: the
     * `tenancy` channel holds the audit trail.
     */
    protected function configureLogViewer(): void
    {
        LogViewer::auth(fn ($request) => $request->user('central') instanceof CentralUser);

        Gate::define('deleteLogFile', fn ($user = null, $file = null) => false);
        Gate::define('deleteLogFolder', fn ($user = null, $folder = null) => false);
    }
}
