<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule tasks
Schedule::command('installments:process-due')
    ->daily()
    ->at('09:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/installments.log'));

Schedule::command('subscriptions:process-due')
    ->daily()
    ->at('08:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/subscriptions.log'));

Schedule::command('summaries:send-daily')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/daily-summaries.log'));

Schedule::command('summaries:send-weekly')
    ->weekly()
    ->sundays()
    ->at('20:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/weekly-summaries.log'));
