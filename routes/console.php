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

// Очистка старых файлов отчётов (старше 1 года)
Schedule::command('reports:cleanup')
    ->dailyAt('03:40')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled reports:cleanup command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-reports-cleanup.log'));

// ДОБАВЛЕНО: Синхронизация записей report_files с бакетом S3
Schedule::command('reports:sync')
    ->dailyAt('03:30')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled reports:sync command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-reports-sync.log'));

// ДОБАВЛЕНО: Почасовая синхронизация размера бакетов организаций
Schedule::command('org:sync-bucket-usage')
    ->hourly()
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled org:sync-bucket-usage command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-org-bucket-usage.log'));

// Синхронизация данных подрядчиков, приглашённых как организации
Schedule::command('contractors:sync-invited')
    ->hourly()
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled contractors:sync-invited command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-contractors-sync-invited.log'));

// Сканирование модулей каждые 15 минут для обновления прав
Schedule::command('modules:scan')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled modules:scan command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-modules-scan.log'));

// Проверка истекших trial периодов модулей каждый час
Schedule::command('modules:convert-expired-trials')
    ->hourly()
    ->withoutOverlapping(10)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled modules:convert-expired-trials command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-trial-expired.log'));

// Проверка алертов Advanced Dashboard каждые 10 минут
Schedule::command('dashboard:check-alerts')
    ->everyTenMinutes()
    ->withoutOverlapping(8)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled dashboard:check-alerts command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-dashboard-alerts.log'));

// Обработка scheduled reports каждые 15 минут
Schedule::command('dashboard:process-scheduled-reports')
    ->everyFifteenMinutes()
    ->withoutOverlapping(12)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled dashboard:process-scheduled-reports command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-dashboard-reports.log'));

Schedule::command('custom-reports:execute-scheduled')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled custom-reports:execute-scheduled command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-custom-reports-execute.log'));

Schedule::command('custom-reports:cleanup-executions --days=90')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled custom-reports:cleanup-executions command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-custom-reports-cleanup.log'));

// Если команда billing:process-renewals также должна быть здесь, ее можно добавить аналогично:
// Schedule::command('billing:process-renewals')->dailyAt('02:00');
