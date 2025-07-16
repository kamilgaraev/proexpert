<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\ReportFile;
use App\Models\Organization;
use App\Services\Storage\OrgBucketService;

class SyncReportFilesCommand extends Command
{
    /**
     * Название и сигнатура консольной команды.
     */
    protected $signature = 'reports:sync {--dry-run : Показать изменения, но не вносить их}';

    /**
     * Описание консольной команды.
     */
    protected $description = 'Сканирует диск reports и добавляет отсутствующие записи в таблицу report_files.';

    public function handle(OrgBucketService $bucketService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $processed = 0;
        $created   = 0;

        Organization::query()->whereNotNull('s3_bucket')->chunkById(50, function ($orgs) use (&$processed, &$created, $bucketService, $dryRun) {
            foreach ($orgs as $org) {
                $disk = $bucketService->getDisk($org);
                $files = $disk->allFiles('reports');

                foreach ($files as $path) {
                    $processed++;
                    if (ReportFile::where('path', $path)->where('organization_id', $org->id)->exists()) {
                        continue;
                    }

                    $filename = basename($path);
                    $type = Str::before(Str::after($path, 'reports/'), '/');
                    $size = $disk->size($path) ?: 0;

                    if ($dryRun) {
                        $this->line("[DRY] {$org->id}: + {$path} ({$size} B)");
                        $created++;
                        continue;
                    }

                    ReportFile::create([
                        'organization_id' => $org->id,
                        'path'       => $path,
                        'type'       => $type ?: 'unknown',
                        'filename'   => $filename,
                        'name'       => $filename,
                        'size'       => $size,
                        'expires_at' => Carbon::now()->addYear(),
                        'user_id'    => null,
                    ]);

                    $created++;
                }
            }
        });

        $this->info("Всего файлов обработано: {$processed}. Новых записей создано: {$created}.");

        if (!$dryRun && $created > 0) {
            Log::info("[reports:sync] Создано {$created} новых записей report_files.");
        }

        return Command::SUCCESS;
    }
} 