<?php

namespace App\BusinessModules\Features\NormativeReferences\Console\Commands;

use App\BusinessModules\Features\NormativeReferences\Services\NormativeResourceImportService;
use Illuminate\Console\Command;

class ImportKsrCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:ksr {file : Путь к файлу Excel (xlsx)} {--source=KSR : Источник данных}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Импорт справочника КСР (Классификатор строительных ресурсов)';

    /**
     * Execute the console command.
     */
    public function handle(NormativeResourceImportService $service): int
    {
        $file = $this->argument('file');
        $source = $this->option('source');

        $this->info("Начинаем импорт файла: {$file}");
        $this->info("Источник: {$source}");

        if (!file_exists($file)) {
            $this->error("Файл не найден!");
            return 1;
        }

        try {
            $startTime = microtime(true);
            $stats = $service->importKsr($file, $source);
            $duration = round(microtime(true) - $startTime, 2);

            $this->info("Импорт завершен за {$duration} сек.");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Processed', $stats['processed']],
                    ['Inserted/Updated', $stats['inserted']],
                    ['Errors', $stats['errors']],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error("Ошибка: " . $e->getMessage());
            return 1;
        }
    }
}
