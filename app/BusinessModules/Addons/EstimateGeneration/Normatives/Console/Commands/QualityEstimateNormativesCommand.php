<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateNormativeQualityService;
use Illuminate\Console\Command;
use RuntimeException;

class QualityEstimateNormativesCommand extends Command
{
    protected $signature = 'estimates:normatives:quality
        {--source=fsnb_2022 : Тип нормативного источника}
        {--version-key= : Ключ версии нормативной базы}
        {--limit=20 : Количество строк в проблемных выборках}';

    protected $description = 'Диагностика полноты и связности импортированной нормативной базы смет';

    public function handle(EstimateNormativeQualityService $qualityService): int
    {
        $source = trim((string) $this->option('source'));
        $versionKey = trim((string) $this->option('version-key'));
        $limit = $this->normalizeLimit($this->option('limit'));

        if ($source === '' || $versionKey === '') {
            $this->error('Укажите --source и --version-key.');

            return self::FAILURE;
        }

        try {
            $report = $qualityService->analyze($source, $versionKey, $limit);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderVersion($report['version']);
        $this->renderTotals($report['totals']);
        $this->renderCollections($report['collections']);
        $this->renderResourceTypes($report['resource_types']);
        $this->renderUnlinkedResources($report['top_unlinked_resources']);
        $this->renderProblemNorms($report['sample_problem_norms']);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $version
     */
    private function renderVersion(array $version): void
    {
        $this->info('Версия нормативной базы');
        $this->table(
            ['ID', 'Источник', 'Версия', 'Статус', 'Файлов', 'Прочитано', 'Импортировано', 'Ошибок'],
            [[
                $version['id'],
                $version['source_type'],
                $version['version_key'],
                $version['status'],
                $version['files_count'],
                $version['rows_read'],
                $version['rows_imported'],
                $version['errors_count'],
            ]]
        );
    }

    /**
     * @param array<string, mixed> $totals
     */
    private function renderTotals(array $totals): void
    {
        $this->info('Сводка качества');
        $this->table(
            ['Показатель', 'Значение'],
            [
                ['Сборников', $totals['collections']],
                ['Разделов', $totals['sections']],
                ['Норм', $totals['norms']],
                ['Норм без раздела', $totals['norms_without_section']],
                ['Норм без ресурсов', $totals['norms_without_resources']],
                ['Ресурсов в нормах', $totals['norm_resources']],
                ['Связанных ресурсов', $totals['linked_norm_resources']],
                ['Несвязанных ресурсов', $totals['unlinked_norm_resources']],
                ['Связность ресурсов, %', $totals['link_rate_percent']],
                ['Ресурсов для связи с КСР', $totals['linkable_norm_resources']],
                ['Связанных с КСР', $totals['linked_linkable_norm_resources']],
                ['Несвязанных с КСР', $totals['unlinked_linkable_norm_resources']],
                ['Связность КСР-ресурсов, %', $totals['linkable_link_rate_percent']],
                ['Трудовых ресурсов', $totals['labor_norm_resources']],
                ['Служебных ресурсов', $totals['summary_norm_resources']],
                ['Цен ресурсов', $totals['resource_prices']],
                ['Связанных цен', $totals['linked_resource_prices']],
                ['Связность цен, %', $totals['price_link_rate_percent']],
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $collections
     */
    private function renderCollections(array $collections): void
    {
        if ($collections === []) {
            return;
        }

        $this->info('Сборники');
        $this->table(
            ['Код', 'Тип', 'Норм', 'Ресурсов', 'Связано', 'Связность, %', 'Связность КСР, %', 'Файл'],
            array_map(
                static fn (array $row): array => [
                    $row['code'],
                    $row['norm_type'],
                    $row['norms_count'],
                    $row['resources_count'],
                    $row['linked_resources_count'],
                    $row['link_rate_percent'],
                    $row['linkable_link_rate_percent'],
                    basename((string) $row['source_file']),
                ],
                $collections
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $resourceTypes
     */
    private function renderResourceTypes(array $resourceTypes): void
    {
        if ($resourceTypes === []) {
            return;
        }

        $this->info('Типы ресурсов в нормах');
        $this->table(
            ['Тип', 'Ресурсов', 'Связано', 'Связность, %'],
            array_map(
                static fn (array $row): array => [
                    $row['resource_type'],
                    $row['resources_count'],
                    $row['linked_resources_count'],
                    $row['link_rate_percent'],
                ],
                $resourceTypes
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     */
    private function renderUnlinkedResources(array $resources): void
    {
        if ($resources === []) {
            $this->info('Несвязанных ресурсов не найдено.');

            return;
        }

        $this->info('Топ несвязанных ресурсов');
        $this->table(
            ['Код', 'Тип', 'Ед.', 'Вхождений', 'Норм', 'Наименование'],
            array_map(
                static fn (array $row): array => [
                    $row['resource_code'],
                    $row['resource_type'],
                    $row['unit'],
                    $row['occurrences_count'],
                    $row['norms_count'],
                    mb_substr((string) $row['resource_name'], 0, 120),
                ],
                $resources
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $norms
     */
    private function renderProblemNorms(array $norms): void
    {
        if ($norms === []) {
            return;
        }

        $this->info('Примеры норм с несвязанными ресурсами');
        $this->table(
            ['Сборник', 'Код нормы', 'Ресурсов', 'Связано', 'Наименование'],
            array_map(
                static fn (array $row): array => [
                    $row['collection_code'],
                    $row['code'],
                    $row['resources_count'],
                    $row['linked_resources_count'],
                    mb_substr((string) $row['name'], 0, 120),
                ],
                $norms
            )
        );
    }

    private function normalizeLimit(mixed $value): int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($limit) ? max(1, min($limit, 100)) : 20;
    }
}
