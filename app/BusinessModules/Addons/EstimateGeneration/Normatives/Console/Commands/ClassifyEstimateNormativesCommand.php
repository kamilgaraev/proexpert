<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateResourceClassificationService;
use Illuminate\Console\Command;
use RuntimeException;

class ClassifyEstimateNormativesCommand extends Command
{
    protected $signature = 'estimates:normatives:classify
        {--source=fsnb_2022 : Тип нормативного источника}
        {--version-key= : Ключ версии нормативной базы}
        {--chunk=1000 : Размер пачки}
        {--dry-run : Посчитать без записи в базу}';

    protected $description = 'Пересчет типов ресурсов в импортированной нормативной базе смет';

    public function handle(EstimateResourceClassificationService $classificationService): int
    {
        $source = trim((string) $this->option('source'));
        $versionKey = trim((string) $this->option('version-key'));
        $chunkSize = $this->normalizeChunkSize($this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if ($source === '' || $versionKey === '') {
            $this->error('Укажите --source и --version-key.');

            return self::FAILURE;
        }

        try {
            $summary = $classificationService->classify($source, $versionKey, $chunkSize, $dryRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Классификация ресурсов завершена.');
        $this->table(
            ['Показатель', 'Значение'],
            [
                ['Источник', $summary['source_type']],
                ['Версия', $summary['version_key']],
                ['Режим проверки', $summary['dry_run'] ? 'да' : 'нет'],
                ['Обработано', $summary['processed']],
                ['Изменено', $summary['updated']],
            ]
        );

        $this->table(
            ['Тип', 'Количество'],
            array_map(
                static fn (string $type, int $count): array => [$type, $count],
                array_keys($summary['by_type']),
                array_values($summary['by_type'])
            )
        );

        return self::SUCCESS;
    }

    private function normalizeChunkSize(mixed $value): int
    {
        $chunkSize = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($chunkSize) ? max(100, min($chunkSize, 10000)) : 1000;
    }
}
