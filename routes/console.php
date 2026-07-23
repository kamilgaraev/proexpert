<?php

use App\BusinessModules\Addons\EstimateGeneration\Jobs\RecoverExpiredTrainingDatasetLeasesJob;
use App\Jobs\LegalArchive\MonitorLegalDocumentOutboxDeadLetters;
use App\Jobs\LegalArchive\RecoverLegalDocumentOutboxMessages;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('commercial:process-renewals --limit=100')
    ->everyMinute()
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('commercial:process-trial-lifecycle')
    ->hourly()
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('commercial:reconcile --limit=100')
    ->dailyAt('02:30')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::job(new RecoverExpiredTrainingDatasetLeasesJob)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::job(new RecoverLegalDocumentOutboxMessages)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('legal-archive:recover-notification-deliveries --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('legal-archive:reconcile --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

Schedule::job(new MonitorLegalDocumentOutboxDeadLetters)
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
use App\Console\Commands\ReverifyOrganizationsCommand;
use Illuminate\Support\Facades\File;

// ... existing commands ...

// Ежемесячная перепроверка организаций (например, 1 числа каждого месяца в 03:00 ночи)
Schedule::command(ReverifyOrganizationsCommand::class)->monthlyOn(1, '03:00');

Schedule::command('estimate-generation:deliver-geometry-regeneration --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5);

use App\BusinessModules\Core\Payments\Jobs\ProcessOverduePaymentsJob;
use App\BusinessModules\Core\Payments\Jobs\SendPaymentRemindersJob;
use App\BusinessModules\Core\Payments\Jobs\SendUpcomingPaymentNotificationsJob;
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

Schedule::command('contractor-referrals:accrue')
    ->hourly()
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled contractor-referrals:accrue command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-contractor-referrals-accrue.log'));

// Сканирование модулей каждые 15 минут для обновления прав
Schedule::command('modules:scan')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled modules:scan command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-modules-scan.log'));

Schedule::command('temp-files:cleanup --hours=48')
    ->dailyAt('04:10')
    ->withoutOverlapping(30)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled temp-files:cleanup command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-temp-files-cleanup.log'));

// Автоматическое геокодирование проектов без координат (раз в день)
Schedule::command('projects:geocode --limit=50 --delay=2')
    ->dailyAt('04:30')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled projects:geocode command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-projects-geocode.log'));

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

// Обработка просроченных платежей (каждый день в 09:00)
Schedule::job(new ProcessOverduePaymentsJob)
    ->dailyAt('09:00')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled ProcessOverduePaymentsJob failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-payments-overdue.log'));

// Отправка напоминаний об утверждении платежей (каждый день в 10:00)
Schedule::job(new SendPaymentRemindersJob)
    ->dailyAt('10:00')
    ->withoutOverlapping(60)
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled SendPaymentRemindersJob failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-payments-reminders.log'));

// Уведомления о предстоящих платежах (каждый день в 09:30)
Schedule::job(new SendUpcomingPaymentNotificationsJob)
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
    ->hourly()
    ->withoutOverlapping(55)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled contracts:sync-event-sourcing command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-contracts-sync.log'));

$ragScheduledLimit = max(1, (int) config('ai-assistant.rag.scheduled_limit', 50));
$ragBackfillCommand = implode(' ', [
    'ai-assistant:rag-backfill',
    '--all',
    '--stale',
    "--limit={$ragScheduledLimit}",
]);

Schedule::command($ragBackfillCommand)
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled ai-assistant:rag-backfill command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-ai-rag-backfill.log'));

Schedule::command('estimates:regional-prices:sync-fgiscs --all-regions --latest-only')
    ->dailyAt('01:00')
    ->withoutOverlapping(720)
    ->createMutexNameUsing('estimate-generation:fgiscs-all-regions:v1')
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled estimates:regional-prices:sync-fgiscs command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-regional-prices-sync.log'));

Schedule::command('estimates:regional-prices:sync-fgiscs-building-resources --all-regions')
    ->dailyAt('13:00')
    ->withoutOverlapping(720)
    ->createMutexNameUsing('estimate-generation:fgiscs-all-regions:v1')
    ->runInBackground()
    ->onFailure(function () {
        Log::channel('stderr')->error('Scheduled estimates:regional-prices:sync-fgiscs-building-resources command failed.');
    })
    ->appendOutputTo(storage_path('logs/schedule-building-resource-prices-sync.log'));

$oneCExchangeScheduledLimit = max(1, (int) config('one_c_exchange.delivery.scheduled_limit', 50));

Schedule::command('contracts:reconcile-audit-debts --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onFailure(function (): void {
        Log::error('contract.audit_reconciliation.schedule_failed');
    });

Schedule::command('legal-signatures:expire --limit=200')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(function (): void {
        Log::error('legal_signature.expiry_schedule_failed');
    });

Schedule::command('legal-signatures:cleanup-storage --limit=200')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onFailure(function (): void {
        Log::error('legal_signature.cleanup_storage_schedule_failed');
    });

Schedule::command('legal-signatures:reconcile-artifacts --limit=200')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onFailure(function (): void {
        Log::error('legal_signature.reconcile_artifacts_schedule_failed');
    });

Schedule::command('legal-documents:cleanup-file-storage --limit=200')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onFailure(function (): void {
        Log::error('legal_document.file_cleanup_storage_schedule_failed');
    });

Schedule::command('immutable-audit:rollout-status')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onFailure(function (): void {
        Log::critical('immutable_audit.rollout_status_failed');
    });

if ((bool) config('one_c_exchange.delivery.enabled', false)) {
    Schedule::command("one-c-exchange:deliver --limit={$oneCExchangeScheduledLimit}")
        ->everyMinute()
        ->withoutOverlapping(10)
        ->onFailure(function () {
            Log::channel('stderr')->error('Scheduled one-c-exchange:deliver command failed.');
        })
        ->appendOutputTo(storage_path('logs/schedule-one-c-exchange-deliver.log'));

    Schedule::command('one-c-exchange:notify-incidents --window-hours=24')
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onFailure(function () {
            Log::channel('stderr')->error('Scheduled one-c-exchange:notify-incidents command failed.');
        })
        ->appendOutputTo(storage_path('logs/schedule-one-c-exchange-incidents.log'));
}

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

Artisan::command('system:clean-logs', function () {
    $logPath = storage_path('logs');
    $files = File::glob($logPath.'/*.log');
    $count = 0;

    foreach ($files as $file) {
        try {
            // Пропускаем .gitignore
            if (basename($file) === '.gitignore') {
                continue;
            }

            File::delete($file);
            $count++;
        } catch (\Exception $e) {
            $this->warn("Could not delete {$file}: ".$e->getMessage());
        }
    }

    $this->info("Logs cleaned! Deleted {$count} files.");
    Log::info("[system:clean-logs] Deleted {$count} log files from {$logPath}");
})->purpose('Clean up all log files in storage/logs')->weekly();
