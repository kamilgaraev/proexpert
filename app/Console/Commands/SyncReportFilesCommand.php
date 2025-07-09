<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\ReportFile;

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

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var \Illuminate\Filesystem\FilesystemAdapter|\Illuminate\Contracts\Filesystem\Cloud $storage */
        $storage = Storage::disk('reports');
        $allFiles = $storage->allFiles();

        $processed = 0;
        $created   = 0;

        foreach ($allFiles as $path) {
            // Пропускаем директории – allFiles возвращает только файлы
            $processed++;

            if (ReportFile::where('path', $path)->exists()) {
                continue;
            }

            $filename = basename($path);
            $type = Str::before($path, '/');
            $size = $storage->size($path) ?: 0;

            if ($dryRun) {
                $this->line("[DRY] Добавлена запись: {$path} (size: {$size})");
                $created++;
                continue;
            }

            ReportFile::create([
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

        $this->info("Всего файлов обработано: {$processed}. Новых записей создано: {$created}.");

        if (!$dryRun && $created > 0) {
            Log::info("[reports:sync] Создано {$created} новых записей report_files.");
        }

        return Command::SUCCESS;
    }
} 