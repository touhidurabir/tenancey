<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('tenant.dashboard', [
            'tenant' => tenant(),
            'userCount' => User::query()->count(),
        ]);
    }
}
