<?php

namespace App\Providers;

use App\Models\CentralUser;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // The default auth callback checks the default (`web`, tenant) guard. Horizon belongs to
        // the central admins, so check the `central` guard instead.
        Horizon::auth(fn ($request) => app()->environment('local')
            || Gate::forUser($request->user('central'))->check('viewHorizon'));

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Any central admin may open Horizon (served on the central domain only, see
        // config/horizon.php `domain`).
        Gate::define('viewHorizon', fn ($user = null) => $user instanceof CentralUser);
    }
}
