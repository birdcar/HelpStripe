<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduled automation layer ticks here. everyFiveMinutes() is a balance
// between "rules feel responsive" and "we're not scanning the queue constantly";
// HelpSpot's own automation runs on a comparable cadence. `composer run dev`
// includes `schedule:work`, so the rules fire during a local demo without a
// cron entry. In production this needs the one-liner cron that calls
// `schedule:run` every minute (documented in docs/tour/06-automation.md).
Schedule::command('automation:run')->everyFiveMinutes();
