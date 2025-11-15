<?php

namespace App\Console\Commands;

use App\Models\ApplicationError;
use Illuminate\Console\Command;

class CleanupOldErrorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'errors:cleanup 
                            {--days= : Number of days to keep (default from config)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Очистка старых ошибок из БД';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days') ?? config('error-tracking.retention_days', 30);
        $dryRun = $this->option('dry-run');

        $this->info("Поиск ошибок старше {$days} дней...");

        $query = ApplicationError::where('last_seen_at', '<', now()->subDays($days));

        $count = $query->count();

        if ($count === 0) {
            $this->info('Нет ошибок для удаления.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("DRY RUN: Было бы удалено {$count} ошибок");
            
            $this->table(
                ['Status', 'Severity', 'Count'],
                $query->select('status', 'severity')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status', 'severity')
                    ->get()
                    ->toArray()
            );

            return self::SUCCESS;
        }

        if (!$this->confirm("Удалить {$count} ошибок?", true)) {
            $this->info('Операция отменена.');
            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Удалено {$deleted} ошибок старше {$days} дней.");

        return self::SUCCESS;
    }
}

