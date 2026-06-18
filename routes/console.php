<?php

use Illuminate\Support\Facades\Schedule;

$schedulerLog = storage_path('logs/cryptospot-scheduler.log');

Schedule::command('cryptospot:scan')
    ->hourly()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(60)
    ->appendOutputTo($schedulerLog);

Schedule::command('cryptospot:daily-gainers')
    ->cron('15 */4 * * *')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(30)
    ->appendOutputTo($schedulerLog);

Schedule::command('cryptospot:missed-gainers')
    ->cron('20 */4 * * *')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(30)
    ->appendOutputTo($schedulerLog);

Schedule::command('cryptospot:cleanup')
    ->dailyAt('03:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(60)
    ->appendOutputTo($schedulerLog);
