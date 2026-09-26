<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('company-notifications:today-digests')->dailyAt('08:55')->timezone('Asia/Tokyo')->withoutOverlapping();
Schedule::command('company-notifications:deliver --limit=100')->everyMinute()->withoutOverlapping();
