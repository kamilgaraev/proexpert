<?php

namespace App\Console\Commands;

use App\Services\Report\CustomReportSchedulerService;
use App\Services\Logging\LoggingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExecuteScheduledReportsCommand extends Command
{
    protected $signature = 'custom-reports:execute-scheduled
                          {--force : Выполнить даже если уже запущено}';

    protected $description = 'Выполнить отчеты по расписанию';

    public function __construct(
        protected CustomReportSchedulerService $schedulerService,
        protected LoggingService $logging
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startTime = microtime(true);

        $this->logging->technical('scheduled_reports.command_started', [
            'command' => 'custom-reports:execute-scheduled',
            'force' => $this->option('force')
        ], 'info');

        $this->info('Выполнение запланированных отчетов...');

        try {
            $result = $this->schedulerService->executeScheduledReports();
            
            $executionTime = (microtime(true) - $startTime) * 1000;

            $this->logging->business('scheduled_reports.command_completed', [
                'command' => 'custom-reports:execute-scheduled',
                'executed_count' => $result['executed'] ?? 0,
                'failed_count' => $result['failed'] ?? 0,
                'skipped_count' => $result['skipped'] ?? 0,
                'execution_time_ms' => round($executionTime, 2)
            ]);

            $this->info('✓ Выполнение завершено успешно');
            $this->info("Выполнено: {$result['executed']}, Ошибок: {$result['failed']}, Пропущено: {$result['skipped']}");
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            $this->logging->technical('scheduled_reports.command_failed', [
                'command' => 'custom-reports:execute-scheduled',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'execution_time_ms' => round($executionTime, 2)
            ], 'error');

            Log::error('[ExecuteScheduledReportsCommand] Command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->error('✗ Ошибка выполнения: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}

