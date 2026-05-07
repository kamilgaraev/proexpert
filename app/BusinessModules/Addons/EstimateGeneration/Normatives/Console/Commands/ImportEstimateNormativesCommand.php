<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateSourceImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportEstimateNormativesCommand extends Command
{
    protected $signature = 'estimates:normatives:import
        {sourceType : Тип нормативного источника}
        {--bucket= : Disk/bucket с исходниками}
        {--prefix= : Prefix внутри estimate-sources/}
        {--version-key= : Ключ версии нормативной базы}';

    protected $description = 'Импорт нормативной базы для генерации смет';

    public function handle(EstimateSourceImportService $importService): int
    {
        $sourceType = (string) $this->argument('sourceType');
        $bucket = (string) $this->option('bucket');
        $prefix = (string) $this->option('prefix');
        $version = (string) $this->option('version-key');

        if ($bucket === '' || $prefix === '' || $version === '') {
            $this->error('Параметры --bucket, --prefix и --version обязательны.');

            return self::FAILURE;
        }

        try {
            $stats = $importService->import($sourceType, $bucket, $prefix, $version);

            $this->info('Импорт нормативной базы завершен.');
            $this->table(
                ['Показатель', 'Значение'],
                [
                    ['Источник', $stats['source_type']],
                    ['Версия', $stats['version_key']],
                    ['Файлов', $stats['files_count']],
                    ['Строк прочитано', $stats['rows_read']],
                    ['Строк импортировано', $stats['rows_imported']],
                    ['Ошибок', $stats['errors_count']],
                ]
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
