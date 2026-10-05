<?php

use App\Models\ImpersonationToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Old impersonation links (App\Models\ImpersonationToken::prunable). Needs `schedule:run` in cron.
Schedule::command('model:prune', ['--model' => [ImpersonationToken::class]])->daily();
