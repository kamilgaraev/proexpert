<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\File as FileModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;

class CleanupFilesCommand extends Command
{
    protected $signature = 'files:cleanup {--disk=\"all\" : Specify a disk to clean, or "all" for all configured storage disks} {--dry-run : Perform a dry run without deleting files} {--hours=24 : Delete files older than this many hours if they are orphaned (relevant for files without DB record)}';

    protected $description = 'Cleans up orphaned files from storage disks and old records from the files table.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $targetDisk = $this->option('disk');
        $ageInHours = (int)$this->option('hours');
        $cutoffTime = now()->subHours($ageInHours);

        $this->info($isDryRun ? 'Starting file cleanup (DRY RUN)...' : 'Starting file cleanup...');

        // 1. Получаем все диски, которые будем проверять
        $disksToScan = [];
        if ($targetDisk === 'all') {
            $disksToScan = array_keys(config('filesystems.disks'));
        } else {
            if (!array_key_exists($targetDisk, config('filesystems.disks'))) {
                $this->error("Disk '{$targetDisk}' is not configured.");
                return Command::FAILURE;
            }
            $disksToScan[] = $targetDisk;
        }
        // Исключаем некоторые диски, которые не предназначены для пользовательских файлов (например, локальные для кэша)
        $disksToScan = array_diff($disksToScan, ['local']); // 'local' часто для storage/app/private, исключим его из авто-скана

        // 2. Получаем все известные файлы из БД (путь => диск)
        $this->info('Fetching known files from database...');
        $dbFiles = [];
        FileModel::query()->select('disk', 'path')->chunk(500, function ($files) use (&$dbFiles) {
            foreach ($files as $file) {
                if (!isset($dbFiles[$file->disk])) {
                    $dbFiles[$file->disk] = [];
                }
                $dbFiles[$file->disk][$file->path] = true;
            }
        });
        $this->info(count($dbFiles, COUNT_RECURSIVE) - count($dbFiles) . ' files found in database across ' . count($dbFiles) . ' disks.');

        // 3. Сканируем диски и ищем "осиротевшие" файлы
        foreach ($disksToScan as $diskName) {
            if ($diskName === 'local') { // Пропускаем корневой 'local', если он не public
                $this->comment("Skipping disk '{$diskName}' as it is typically for internal storage.");
                continue;
            }
             if (!config("filesystems.disks.{$diskName}")) {
                $this->warn("Skipping disk '{$diskName}' as it is not fully configured or accessible for scanning.");
                continue;
            }

            $this->info("Scanning disk: {$diskName}...");
            try {
                /** @var FilesystemContract $storage */
                $storage = Storage::disk($diskName);
                $allFilesInDisk = LazyCollection::make(function () use ($storage) {
                    $files = $storage->allFiles(); // Рекурсивно получает все файлы
                    foreach ($files as $file) {
                        yield $file;
                    }
                });

                $orphanedCount = 0;
                foreach ($allFilesInDisk as $filePath) {
                    // Игнорируем скрытые файлы и файлы в "особых" директориях
                    if (Str::startsWith(basename($filePath), '.') || Str::contains($filePath, '/.git/') || Str::contains($filePath, '_thumbs/')) {
                        continue;
                    }

                    if (!isset($dbFiles[$diskName][$filePath])) {
                        // Файл есть на диске, но нет в БД
                        // Дополнительная проверка по времени модификации
                        if ($storage->lastModified($filePath) < $cutoffTime->getTimestamp()) {
                            $this->line("Orphaned file found on disk '{$diskName}': {$filePath} (older than {$ageInHours}h)");
                            $orphanedCount++;
                            if (!$isDryRun) {
                                try {
                                    $storage->delete($filePath);
                                    Log::info("[FilesCleanup] Deleted orphaned file: {$filePath} from disk {$diskName}");
                                    // Попытка удалить связанные миниатюры, если это основной файл
                                    // (логика предсказания миниатюр может быть сложной)
                                    $this->tryDeletePredictedThumbnails($storage, $filePath);
                                } catch (\Exception $e) {
                                    $this->error("Error deleting file {$filePath} from disk {$diskName}: " . $e->getMessage());
                                    Log::error("[FilesCleanup] Error deleting orphaned file {$filePath} from disk {$diskName}: " . $e->getMessage());
                                }
                            }
                        } else {
                             $this->comment("Potential orphan on disk '{$diskName}': {$filePath} (but newer than {$ageInHours}h, skipped)");
                        }
                    }
                }
                $this->info("Found {$orphanedCount} orphaned files on disk '{$diskName}' older than {$ageInHours}h to delete." . ($isDryRun ? " (DRY RUN)" : ""));

            } catch (\Exception $e) {
                $this->error("Could not scan disk '{$diskName}': " . $e->getMessage());
                Log::error("[FilesCleanup] Could not scan disk '{$diskName}': " . $e->getMessage());
            }
        }

        // 4. (Опционально) Очистка "мертвых" записей из таблицы `files`
        // Это записи, где fileable_id/fileable_type указывают на несуществующие модели
        // Этот шаг более сложный и требует проверки каждой fileable-связи, пока пропустим.
        // Можно добавить, если будет много таких записей и это станет проблемой.

        $this->info('File cleanup process finished.');
        return Command::SUCCESS;
    }

    /**
     * Tries to delete predicted thumbnail files for a given original file path.
     * Assumes thumbnails are in a '_thumbs' subdirectory relative to the original file.
     */
    protected function tryDeletePredictedThumbnails(FilesystemContract $storage, string $originalPath): void
    {
        $originalDirectory = dirname($originalPath);
        $originalFileName = pathinfo($originalPath, PATHINFO_FILENAME);
        $originalExtension = pathinfo($originalPath, PATHINFO_EXTENSION);
        $thumbDirectory = ($originalDirectory === '.' ? '' : $originalDirectory . '/') . '_thumbs';

        if ($storage->exists($thumbDirectory)) {
            $potentialThumbs = $storage->files($thumbDirectory);
            foreach ($potentialThumbs as $thumbPath) {
                // Проверяем, начинается ли имя файла миниатюры с имени оригинального файла
                if (Str::startsWith(basename($thumbPath), $originalFileName . '_')) {
                    if (pathinfo($thumbPath, PATHINFO_EXTENSION) === $originalExtension) {
                        try {
                            $storage->delete($thumbPath);
                            $this->comment("Deleted predicted thumbnail: {$thumbPath}");
                            Log::info("[FilesCleanup] Deleted predicted thumbnail: {$thumbPath} for original {$originalPath}");
                        } catch (\Exception $e) {
                            $this->error("Error deleting predicted thumbnail {$thumbPath}: " . $e->getMessage());
                            Log::error("[FilesCleanup] Error deleting predicted thumbnail {$thumbPath}: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
} 