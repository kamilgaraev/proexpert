<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateImportStatisticsService;
use Illuminate\Console\Command;

class InspectEstimateNormativesCommand extends Command
{
    protected $signature = 'estimates:normatives:inspect
        {--source= : Тип нормативного источника}
        {--version-key= : Ключ версии нормативной базы}';

    protected $description = 'Просмотр статусов и ошибок импорта нормативной базы';

    public function handle(EstimateImportStatisticsService $statisticsService): int
    {
        $source = $this->option('source') !== null ? (string) $this->option('source') : null;
        $version = $this->option('version-key') !== null ? (string) $this->option('version-key') : null;
        $stats = $statisticsService->inspect($source, $version);

        $this->info('Версии нормативной базы');
        $this->table(
            ['ID', 'Источник', 'Версия', 'Статус', 'Файлов', 'Прочитано', 'Импортировано', 'Ошибок'],
            array_map(
                static fn (array $row): array => [
                    $row['id'],
                    $row['source_type'],
                    $row['version_key'],
                    $row['status'],
                    $row['files_count'],
                    $row['rows_read'],
                    $row['rows_imported'],
                    $row['errors_count'],
                ],
                $stats['versions']
            )
        );

        if ($stats['errors'] !== []) {
            $this->info('Последние ошибки');
            $this->table(
                ['ID', 'Источник', 'Версия', 'Файл', 'Строка', 'Уровень', 'Сообщение'],
                array_map(
                    static fn (array $row): array => [
                        $row['id'],
                        $row['source_type'],
                        $row['version_key'],
                        $row['source_file'],
                        $row['row_number'],
                        $row['severity'],
                        $row['message'],
                    ],
                    $stats['errors']
                )
            );
        }

        return self::SUCCESS;
    }
}
