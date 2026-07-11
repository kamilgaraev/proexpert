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
            $startedAt = microtime(true);
            $stats = $importService->import($sourceType, $bucket, $prefix, $version, function (string $event, array $payload) use ($startedAt): void {
                $elapsed = max(0, (int) floor(microtime(true) - $startedAt));
                $prefix = sprintf('[%s +%ss]', now()->format('Y-m-d H:i:s'), $elapsed);

                if ($event === 'file_started') {
                    $this->line(sprintf('%s Начат файл: %s', $prefix, $payload['file'] ?? ''));

                    return;
                }

                if ($event === 'file_finished') {
                    $this->line(sprintf(
                        '%s Завершен файл: %s, прочитано: %s, импортировано: %s, ошибок: %s',
                        $prefix,
                        $payload['file'] ?? '',
                        $payload['rows_read'] ?? 0,
                        $payload['rows_imported'] ?? 0,
                        $payload['errors_count'] ?? 0
                    ));

                    return;
                }

                if ($event === 'rows_progress') {
                    $this->line(sprintf(
                        '%s Прогресс: %s, прочитано: %s, импортировано: %s, ошибок: %s',
                        $prefix,
                        basename((string) ($payload['file'] ?? '')),
                        $payload['rows_read'] ?? 0,
                        $payload['rows_imported'] ?? 0,
                        $payload['errors_count'] ?? 0
                    ));
                }
            });

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
            $this->error(trans_message('estimate_generation.operation_error'));

            return self::FAILURE;
        }
    }
}
