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

// Ежедневная очистка "осиротевших" файлов (старше 72 часов)
// Запускается на всех сконфигурированных дисках, кроме 'local' (как определено в команде)
Schedule::command('files:cleanup --disk="all" --hours=72')
    ->dailyAt('03:00')
    ->withoutOverlapping(120) // Не запускать, если предыдущий экземпляр еще работает (макс 2 часа)
    ->onFailure(function () {
        // Можно добавить уведомление администратору
        Log::channel('stderr')->error('Scheduled files:cleanup command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-files-cleanup.log')); // Логировать вывод

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
