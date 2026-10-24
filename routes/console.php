<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('enterprise-portfolio:sync-github-issues')->dailyAt('02:15');

\Illuminate\Support\Facades\Schedule::command('workflow:dispatch-pending-continuations')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('social-accounts:sync-facebook-follower-counts')->everySixHours()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('social-accounts:sync-instagram-follower-counts')->everySixHours()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('lifecycle:capture-daily-snapshots')->dailyAt('00:00')->timezone((string) config('app.timezone'))->withoutOverlapping()->onOneServer();
