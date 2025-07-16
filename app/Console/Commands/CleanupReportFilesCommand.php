<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\ReportFile;
use App\Models\Organization;
use App\Services\Storage\OrgBucketService;

class CleanupReportFilesCommand extends Command
{
    protected $signature = 'reports:cleanup {--dry-run : Show files to delete without removing} {--days=365 : Delete files older than N days}';

    protected $description = 'Удаляет из S3-диска reports файлы отчётов, которым более указанного количества дней (по умолчанию – 1 год).';

    public function handle(OrgBucketService $bucketService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days   = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        $totalDeleted = 0;

        Organization::query()->whereNotNull('s3_bucket')->chunkById(50, function ($orgs) use ($bucketService, $cutoff, $dryRun, &$totalDeleted) {
            foreach ($orgs as $org) {
                $disk = $bucketService->getDisk($org);
                $files = $disk->allFiles('reports');

                // 1) удаляем записи БД, если файла нет или просрочен expires_at
                ReportFile::where('organization_id', $org->id)
                    ->where(function($q) use ($files) { $q->whereNotIn('path', $files)->orWhere('expires_at','<', now()); })
                    ->delete();

                $toDelete = [];
                foreach ($files as $path) {
                    $lastModified = Carbon::createFromTimestamp($disk->lastModified($path));
                    if ($lastModified->lessThan($cutoff)) {
                        $toDelete[] = $path;
                    }
                }

                if ($dryRun) {
                    foreach ($toDelete as $p) {
                        $this->line("[DRY] {$org->id}: {$p}");
                    }
                    continue;
                }

                foreach ($toDelete as $p) {
                    $disk->delete($p);
                    ReportFile::where('organization_id', $org->id)->where('path', $p)->delete();
                    $totalDeleted++;
                }
            }
        });

        if ($dryRun) {
            $this->info('DRY RUN завершён.');
            return Command::SUCCESS;
        }

        $this->info("Удалено {$totalDeleted} файлов.");
        return Command::SUCCESS;
    }
} 