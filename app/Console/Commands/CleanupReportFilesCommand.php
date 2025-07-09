<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class CleanupReportFilesCommand extends Command
{
    protected $signature = 'reports:cleanup {--dry-run : Show files to delete without removing} {--days=365 : Delete files older than N days}';

    protected $description = 'Удаляет из S3-диска reports файлы отчётов, которым более указанного количества дней (по умолчанию – 1 год).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days   = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        /** @var \Illuminate\Filesystem\FilesystemAdapter|\Illuminate\Contracts\Filesystem\Cloud $storage */
        $storage = Storage::disk('reports');
        $files = $storage->allFiles();

        $toDelete = [];
        foreach ($files as $path) {
            $lastModified = Carbon::createFromTimestamp($storage->lastModified($path));
            if ($lastModified->lessThan($cutoff)) {
                $toDelete[] = $path;
            }
        }

        $count = count($toDelete);
        if ($count === 0) {
            $this->info('Нет файлов для удаления.');
            return Command::SUCCESS;
        }

        $this->info("Файлов для удаления: {$count}");

        if ($dryRun) {
            foreach ($toDelete as $path) {
                $this->line($path);
            }
            $this->info('DRY RUN – файлы не удалены.');
            return Command::SUCCESS;
        }

        foreach ($toDelete as $path) {
            try {
                $storage->delete($path);
                Log::info("[reports:cleanup] Deleted {$path}");
            } catch (\Exception $e) {
                $this->error("Ошибка удаления {$path}: " . $e->getMessage());
                Log::error("[reports:cleanup] Error deleting {$path}: " . $e->getMessage());
            }
        }

        $this->info("Удалено {$count} файлов.");
        return Command::SUCCESS;
    }
} 