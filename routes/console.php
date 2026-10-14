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
