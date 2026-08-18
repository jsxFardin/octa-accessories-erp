<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ncr:notify-overdue')
    ->dailyAt('01:00')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();
