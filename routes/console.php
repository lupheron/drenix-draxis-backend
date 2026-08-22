<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('access:expire')->daily();
Schedule::command('sync:ringcentral JM')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sync:monday JM')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sync:driver-leads JM')->hourly()->withoutOverlapping();
Schedule::command('sync:attendance')->everyFifteenMinutes()->withoutOverlapping();
