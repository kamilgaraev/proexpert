<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\ReverifyOrganizationsCommand;

// ... existing commands ...

// Ежемесячная перепроверка организаций (например, 1 числа каждого месяца в 03:00 ночи)
Schedule::command(ReverifyOrganizationsCommand::class)->monthlyOn(1, '03:00');

use Illuminate\Support\Facades\Log;
use App\BusinessModules\Core\Payments\Jobs\ProcessOverduePaymentsJob;
use App\BusinessModules\Core\Payments\Jobs\SendPaymentRemindersJob;
use App\BusinessModules\Core\Payments\Jobs\SendUpcomingPaymentNotificationsJob;

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
    ->everyFiveMinutes()
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

// Автоматическое геокодирование проектов без координат (раз в день)
Schedule::command('projects:geocode --limit=50 --delay=2')
    ->dailyAt('04:30')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled projects:geocode command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-projects-geocode.log'));

// Автоматическое продление подписок (3 раза в день для надежности)
Schedule::command('subscriptions:renew --days-ahead=1')
    ->dailyAt('02:00')
    ->withoutOverlapping(120)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled subscriptions:renew command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-subscriptions-renew.log'));

Schedule::command('subscriptions:renew --days-ahead=1')
    ->dailyAt('12:00')
    ->withoutOverlapping(120)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled subscriptions:renew command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-subscriptions-renew.log'));

Schedule::command('subscriptions:renew --days-ahead=1')
    ->dailyAt('20:00')
    ->withoutOverlapping(120)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled subscriptions:renew command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-subscriptions-renew.log'));

// Автоматическое снятие истекших ограничений доступа
Schedule::command('restrictions:lift-expired')
    ->hourly()
    ->withoutOverlapping(30)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled restrictions:lift-expired command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-restrictions-lift.log'));

// ============================================
// PAYMENTS MODULE - Автоматизация платежей
// ============================================

// Синхронизация invoices с payment_documents (каждую минуту)
Schedule::command('payments:sync-invoices-documents --limit=50')
    ->everyMinute()
    ->withoutOverlapping(55)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled payments:sync-invoices-documents command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-payments-sync-invoices.log'));

// Обработка просроченных платежей (каждый день в 09:00)
Schedule::job(new ProcessOverduePaymentsJob())
    ->dailyAt('09:00')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled ProcessOverduePaymentsJob failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-payments-overdue.log'));

// Отправка напоминаний об утверждении платежей (каждый день в 10:00)
Schedule::job(new SendPaymentRemindersJob())
    ->dailyAt('10:00')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled SendPaymentRemindersJob failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-payments-reminders.log'));

// Уведомления о предстоящих платежах (каждый день в 09:30)
Schedule::job(new SendUpcomingPaymentNotificationsJob())
    ->dailyAt('09:30')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled SendUpcomingPaymentNotificationsJob failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-payments-upcoming.log'));

// ============================================
// CONTRACT EVENT SOURCING - Автоматическая синхронизация
// ============================================

// Синхронизация total_amount контрактов с Event Sourcing (каждую минуту для мгновенного исправления)
Schedule::command('contracts:sync-event-sourcing')
    ->everyMinute()
    ->withoutOverlapping(55)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled contracts:sync-event-sourcing command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-contracts-sync.log'));

Artisan::command('projects:geocode-help', function () {
    $this->info('Available geocoding command:');
    $this->info('  php artisan projects:geocode [options]');
    $this->newLine();
    $this->info('Options:');
    $this->info('  --force              Force re-geocode all projects even if they already have coordinates');
    $this->info('  --organization=ID    Geocode projects only for specific organization ID');
    $this->info('  --limit=N            Limit the number of projects to geocode');
    $this->info('  --delay=N            Delay in seconds between geocoding requests (default: 1)');
    $this->newLine();
    $this->info('Examples:');
    $this->info('  php artisan projects:geocode');
    $this->info('  php artisan projects:geocode --organization=1 --limit=10');
    $this->info('  php artisan projects:geocode --force --delay=3');
})->purpose('Show help for geocoding projects command');
