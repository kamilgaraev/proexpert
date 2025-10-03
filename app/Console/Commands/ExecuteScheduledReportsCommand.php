<?php

namespace App\Console\Commands;

use App\Services\Report\CustomReportSchedulerService;
use Illuminate\Console\Command;

class ExecuteScheduledReportsCommand extends Command
{
    protected $signature = 'custom-reports:execute-scheduled
                          {--force : Выполнить даже если уже запущено}';

    protected $description = 'Выполнить отчеты по расписанию';

    public function __construct(
        protected CustomReportSchedulerService $schedulerService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Выполнение запланированных отчетов...');

        try {
            $this->schedulerService->executeScheduledReports();
            
            $this->info('✓ Выполнение завершено успешно');
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('✗ Ошибка выполнения: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}

