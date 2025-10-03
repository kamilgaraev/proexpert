<?php

namespace App\Console\Commands;

use App\Models\CustomReportExecution;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CleanupOldReportExecutionsCommand extends Command
{
    protected $signature = 'custom-reports:cleanup-executions
                          {--days=90 : Количество дней для хранения истории}';

    protected $description = 'Очистка старых записей о выполнении отчетов';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("Удаление записей старше {$days} дней (до {$cutoffDate->format('Y-m-d')})...");

        $deletedCount = CustomReportExecution::where('created_at', '<', $cutoffDate)
            ->where('status', CustomReportExecution::STATUS_COMPLETED)
            ->delete();

        $this->info("✓ Удалено записей: {$deletedCount}");

        return Command::SUCCESS;
    }
}

