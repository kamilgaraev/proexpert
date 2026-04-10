<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\ReportFile;
use App\Models\Organization;
use App\Services\Storage\OrgBucketService;
use Illuminate\Database\UniqueConstraintViolationException;

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
                    $filename = basename($path);
                    $type = Str::before(Str::after($path, 'reports/'), '/');
                    $size = $disk->size($path) ?: 0;

                    if ($dryRun) {
                        if (ReportFile::query()->where('path', $path)->exists()) {
                            continue;
                        }

                        $this->line("[DRY] {$org->id}: + {$path} ({$size} B)");
                        $created++;
                        continue;
                    }

                    try {
                        $reportFile = ReportFile::query()->firstOrCreate(
                            ['path' => $path],
                            [
                                'organization_id' => $org->id,
                                'type' => $type ?: 'unknown',
                                'filename' => $filename,
                                'name' => $filename,
                                'size' => $size,
                                'expires_at' => Carbon::now()->addYear(),
                                'user_id' => null,
                            ]
                        );

                        if (!$reportFile->wasRecentlyCreated) {
                            if ($reportFile->organization_id !== null && (int) $reportFile->organization_id !== (int) $org->id) {
                                Log::warning('[reports:sync] Existing report file belongs to another organization', [
                                    'path' => $path,
                                    'existing_organization_id' => $reportFile->organization_id,
                                    'scanned_organization_id' => $org->id,
                                ]);
                            }

                            continue;
                        }

                        $created++;
                    } catch (UniqueConstraintViolationException $e) {
                        Log::warning('[reports:sync] Duplicate path detected during sync', [
                            'path' => $path,
                            'organization_id' => $org->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
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
