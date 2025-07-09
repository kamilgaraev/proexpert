<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Запланированная очистка "битых" аватаров (если файл отсутствует на диске)
Schedule::command('avatars:cleanup')
    ->dailyAt('03:20')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled avatars:cleanup command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-avatars-cleanup.log'));

// Если команда billing:process-renewals также должна быть здесь, ее можно добавить аналогично:
// Schedule::command('billing:process-renewals')->dailyAt('02:00');
