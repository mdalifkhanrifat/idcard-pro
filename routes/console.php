<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Old records of prunable models (e.g. card verification checks past their keeping period)
Schedule::command('model:prune')->daily();
// Sent messages past the days an organization keeps them, only where it chose to have them deleted
Schedule::command('app:prune-notification-log')->daily()->withoutOverlapping();
// Reminders before appointments, once each, as each organization set them (5.7c)
Schedule::command('app:send-appointment-reminders')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// Reminders before cards' last days, once each, in each organization's morning (5.7d)
Schedule::command('app:send-card-expiry-reminders')->hourly()->withoutOverlapping()->onOneServer();
