<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CleanupTempFilesCommand extends Command
{
    protected $signature = 'temp-files:cleanup {--dry-run : Show files to delete without removing} {--hours=48 : Delete temp files older than N hours}';

    protected $description = 'Deletes old temporary files from storage/app/temp.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hours = max(1, (int) $this->option('hours'));
        $tempPath = storage_path('app/temp');

        if (!File::isDirectory($tempPath)) {
            $this->info('Temp directory does not exist.');

            return Command::SUCCESS;
        }

        $cutoffTimestamp = now()->subHours($hours)->timestamp;
        $deleted = 0;
        $matched = 0;

        foreach (File::files($tempPath) as $file) {
            if ($file->getMTime() >= $cutoffTimestamp) {
                continue;
            }

            $matched++;
            $path = $file->getPathname();

            if ($dryRun) {
                $this->line("[DRY] {$path}");
                continue;
            }

            if (File::delete($path)) {
                $deleted++;
            }
        }

        $this->info($dryRun
            ? "Matched {$matched} old temp files."
            : "Deleted {$deleted} old temp files.");

        return Command::SUCCESS;
    }
}
