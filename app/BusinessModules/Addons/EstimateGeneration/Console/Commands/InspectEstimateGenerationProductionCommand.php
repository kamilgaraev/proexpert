<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationAuditService;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class InspectEstimateGenerationProductionCommand extends Command
{
    protected $signature = 'estimate-generation:production-check
        {--session_id= : Проверить одну сессию генерации}
        {--organization_id= : Ограничить отчет организацией}
        {--project_id= : Ограничить отчет проектом}
        {--top=20 : Количество строк в списках риска}
        {--require-full-pricing : Завершить проверку с ошибкой, если есть позиции без нормативного расчета}
        {--json : Вывести отчет в JSON}';

    protected $description = 'Read-only диагностика AI-генерации смет, learning examples и RAG-индекса.';

    public function handle(): int
    {
        $report = [
            'filters' => $this->filters(),
            'normative_decisions' => $this->normativeDecisionSummary(),
            'priced_risk_lines' => $this->pricedRiskLines(),
            'review_priced_lines' => $this->reviewPricedLines(),
            'full_pricing' => $this->fullPricingSummary(),
            'learning_examples' => $this->learningExamplesSummary(),
            'rag_learning_source' => $this->ragLearningSourceSummary(),
            'dataset_status' => $this->datasetStatus(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $this->fullPricingExitCode($report['full_pricing']);
        }

        $this->info('Фильтры');
        $this->table(['Параметр', 'Значение'], array_map(
            static fn (string $key, mixed $value): array => [$key, $value ?? ''],
            array_keys($report['filters']),
            $report['filters']
        ));

        $this->info('Подбор норм');
        $this->table(['Метрика', 'Значение'], array_map(
            static fn (string $key, mixed $value): array => [$key, $value],
            array_keys($report['normative_decisions']),
            $report['normative_decisions']
        ));

        $this->info('Рассчитанные строки с риск-флагами');
        $this->table(
            ['Сессия', 'Пакет', 'Позиция', 'Сумма', 'Норма', 'Флаги'],
            $report['priced_risk_lines']
        );

        $this->info('Рассчитанные строки на проверку');
        $this->table(
            ['Сессия', 'Пакет', 'Позиция', 'Сумма', 'Норма', 'Флаги'],
            $report['review_priced_lines']
        );

        $this->info('Покрытие расчетом');
        $this->table(['Метрика', 'Значение'], array_map(
            static fn (string $key, mixed $value): array => [$key, $value],
            array_keys($report['full_pricing']),
            $report['full_pricing']
        ));

        $this->info('Learning examples');
        $this->table(['Метрика', 'Значение'], array_map(
            static fn (string $key, mixed $value): array => [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value],
            array_keys($report['learning_examples']),
            $report['learning_examples']
        ));

        $this->info('RAG source estimate_generation_learning');
        $this->table(['Метрика', 'Значение'], array_map(
            static fn (string $key, mixed $value): array => [$key, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value],
            array_keys($report['rag_learning_source']),
            $report['rag_learning_source']
        ));

        return $this->fullPricingExitCode($report['full_pricing']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'session_id' => $this->nullableIntOption('session_id'),
            'organization_id' => $this->nullableIntOption('organization_id'),
            'project_id' => $this->nullableIntOption('project_id'),
            'top' => $this->topLimit(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function normativeDecisionSummary(): array
    {
        $summary = [
            'events_count' => 0,
            'sessions_count' => $this->sessionQuery()->count(),
            'accepted' => 0,
            'review_priced' => 0,
            'candidate_only' => 0,
            'not_found' => 0,
            'unit_mismatch' => 0,
            'scope_mismatch' => 0,
            'safe_norm_required' => 0,
            'max_line_total' => 0.0,
        ];

        $this->auditEventQuery()
            ->lazyById(500)
            ->each(function (EstimateGenerationAuditEvent $event) use (&$summary): void {
                $payload = is_array($event->payload) ? $event->payload : [];
                $summary['events_count']++;

                foreach (['accepted', 'review_priced', 'candidate_only', 'not_found', 'unit_mismatch', 'scope_mismatch', 'safe_norm_required'] as $key) {
                    $summary[$key] += (int) ($payload[$key] ?? 0);
                }

                $summary['max_line_total'] = max((float) $summary['max_line_total'], (float) ($payload['max_line_total'] ?? 0));
            });

        $summary['max_line_total'] = round((float) $summary['max_line_total'], 2);

        return $summary;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function pricedRiskLines(): array
    {
        return $this->packageItemQuery()
            ->where('total_cost', '>', 0)
            ->latest('id')
            ->limit(5000)
            ->get()
            ->filter(fn (EstimateGenerationPackageItem $item): bool => $this->hardRiskFlags($item) !== [])
            ->sortByDesc(static fn (EstimateGenerationPackageItem $item): float => (float) $item->total_cost)
            ->take($this->topLimit())
            ->map(fn (EstimateGenerationPackageItem $item): array => $this->linePayload($item, $this->hardRiskFlags($item)))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function reviewPricedLines(): array
    {
        return $this->packageItemQuery()
            ->where('total_cost', '>', 0)
            ->latest('id')
            ->limit(5000)
            ->get()
            ->filter(fn (EstimateGenerationPackageItem $item): bool => $this->reviewPriced($item))
            ->sortByDesc(static fn (EstimateGenerationPackageItem $item): float => (float) $item->total_cost)
            ->take($this->topLimit())
            ->map(fn (EstimateGenerationPackageItem $item): array => $this->linePayload($item, $this->lineFlags($item)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function fullPricingSummary(): array
    {
        $summary = [
            'priced_work_items' => 0,
            'calculated_work_items' => 0,
            'not_calculated_work_items' => 0,
            'market_estimate_work_items' => 0,
            'safe_norm_required_work_items' => 0,
            'pricing_coverage' => 0.0,
        ];

        $this->packageItemQuery()
            ->where('item_type', 'priced_work')
            ->lazyById(500)
            ->each(function (EstimateGenerationPackageItem $item) use (&$summary): void {
                $summary['priced_work_items']++;

                $flags = $this->lineFlags($item);
                $metadata = is_array($item->metadata) ? $item->metadata : [];
                $pricingStatus = (string) ($metadata['pricing_status'] ?? '');
                $isNotCalculated = (float) $item->total_cost <= 0
                    || $pricingStatus === 'not_calculated'
                    || in_array('pricing_not_calculated', $flags, true)
                    || in_array('safe_norm_required', $flags, true);

                if ($isNotCalculated) {
                    $summary['not_calculated_work_items']++;
                } else {
                    $summary['calculated_work_items']++;
                }

                if ($item->price_source === 'market_estimate' || in_array('market_price_used', $flags, true)) {
                    $summary['market_estimate_work_items']++;
                }

                if (in_array('safe_norm_required', $flags, true)) {
                    $summary['safe_norm_required_work_items']++;
                }
            });

        $summary['pricing_coverage'] = $summary['priced_work_items'] > 0
            ? round($summary['calculated_work_items'] / $summary['priced_work_items'], 4)
            : 0.0;

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function learningExamplesSummary(): array
    {
        $query = $this->learningExampleQuery();
        $bySource = (clone $query)
            ->selectRaw('source_type, count(*) as aggregate_count')
            ->groupBy('source_type')
            ->pluck('aggregate_count', 'source_type')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'total' => (clone $query)->count(),
            'positive' => (clone $query)->where('is_positive', true)->count(),
            'negative' => (clone $query)->where('is_positive', false)->count(),
            'indexed' => (clone $query)->whereNotNull('indexed_at')->count(),
            'by_source_type' => $bySource,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ragLearningSourceSummary(): array
    {
        $sourceType = 'estimate_generation_learning';
        $sourceQuery = RagSource::query()->where('source_type', $sourceType);
        $chunkQuery = RagChunk::query()->whereHas('source', static fn (Builder $query): Builder => $query->where('source_type', $sourceType));
        $runQuery = RagIndexRun::query()->where('source_type', $sourceType);

        $this->applyOrganizationAndProject($sourceQuery);
        $this->applyOrganizationAndProject($chunkQuery);
        $this->applyOrganizationAndProject($runQuery);

        $latestRun = (clone $runQuery)->latest('id')->first();

        return [
            'source_type' => $sourceType,
            'sources_count' => (clone $sourceQuery)->count(),
            'chunks_count' => (clone $chunkQuery)->count(),
            'latest_run_id' => $latestRun?->id,
            'latest_run_status' => $latestRun?->status,
            'latest_run_finished_at' => $latestRun?->finished_at?->toISOString(),
            'indexed' => (clone $sourceQuery)->count() > 0 && (clone $chunkQuery)->count() > 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function datasetStatus(): array
    {
        return EstimateDatasetVersion::query()
            ->whereIn('source_type', ['fsnb_2022', 'fsbc', 'fgis_labor_prices'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(static fn (EstimateDatasetVersion $version): array => [
                'id' => $version->id,
                'source_type' => $version->source_type?->value ?? $version->source_type,
                'version_key' => $version->version_key,
                'status' => $version->status?->value ?? $version->status,
                'rows_imported' => $version->rows_imported,
                'errors_count' => $version->errors_count,
            ])
            ->values()
            ->all();
    }

    private function sessionQuery(): Builder
    {
        $query = EstimateGenerationSession::query();
        $this->applySessionFilters($query);

        return $query;
    }

    private function auditEventQuery(): Builder
    {
        $query = EstimateGenerationAuditEvent::query()
            ->where('event_type', EstimateGenerationAuditService::EVENT_NORMATIVE_DECISION_SUMMARY);

        $sessionId = $this->nullableIntOption('session_id');
        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }

        $organizationId = $this->nullableIntOption('organization_id');
        $projectId = $this->nullableIntOption('project_id');
        if ($organizationId !== null || $projectId !== null) {
            $query->whereHas('session', function (Builder $query) use ($organizationId, $projectId): void {
                if ($organizationId !== null) {
                    $query->where('organization_id', $organizationId);
                }

                if ($projectId !== null) {
                    $query->where('project_id', $projectId);
                }
            });
        }

        return $query;
    }

    private function packageItemQuery(): Builder
    {
        $query = EstimateGenerationPackageItem::query()
            ->with('package.session');

        $sessionId = $this->nullableIntOption('session_id');
        $organizationId = $this->nullableIntOption('organization_id');
        $projectId = $this->nullableIntOption('project_id');

        if ($sessionId !== null || $organizationId !== null || $projectId !== null) {
            $query->whereHas('package.session', function (Builder $query) use ($sessionId, $organizationId, $projectId): void {
                if ($sessionId !== null) {
                    $query->whereKey($sessionId);
                }

                if ($organizationId !== null) {
                    $query->where('organization_id', $organizationId);
                }

                if ($projectId !== null) {
                    $query->where('project_id', $projectId);
                }
            });
        }

        return $query;
    }

    private function learningExampleQuery(): Builder
    {
        $query = EstimateGenerationLearningExample::query();
        $this->applyOrganizationAndProject($query);

        return $query;
    }

    private function applySessionFilters(Builder $query): void
    {
        $sessionId = $this->nullableIntOption('session_id');
        if ($sessionId !== null) {
            $query->whereKey($sessionId);
        }

        $this->applyOrganizationAndProject($query);
    }

    private function applyOrganizationAndProject(Builder $query): void
    {
        $organizationId = $this->nullableIntOption('organization_id');
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $projectId = $this->nullableIntOption('project_id');
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function linePayload(EstimateGenerationPackageItem $item, array $flags): array
    {
        $match = is_array($item->metadata['normative_match'] ?? null) ? $item->metadata['normative_match'] : [];

        return [
            $item->package?->session_id,
            $item->package?->key,
            mb_substr($item->name, 0, 120),
            round((float) $item->total_cost, 2),
            $match['code'] ?? null,
            implode(', ', $flags),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function hardRiskFlags(EstimateGenerationPackageItem $item): array
    {
        return array_values(array_intersect($this->lineFlags($item), [
            'unit_mismatch',
            'scope_mismatch',
            'norm_without_resources',
            'norm_without_prices',
            'norm_without_resource_prices',
            'safe_norm_required',
            'pricing_not_calculated',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function lineFlags(EstimateGenerationPackageItem $item): array
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $match = is_array($metadata['normative_match'] ?? null) ? $metadata['normative_match'] : [];
        $decision = is_array($match['decision'] ?? null) ? $match['decision'] : [];
        $flags = is_array($item->flags) ? $item->flags : [];
        $matchWarnings = is_array($match['warnings'] ?? null) ? $match['warnings'] : [];
        $decisionWarnings = is_array($decision['warnings'] ?? null) ? $decision['warnings'] : [];

        return array_values(array_unique(array_filter(array_map('strval', [
            ...$flags,
            ...$matchWarnings,
            ...$decisionWarnings,
            (string) ($decision['status'] ?? ''),
        ]))));
    }

    private function reviewPriced(EstimateGenerationPackageItem $item): bool
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $match = is_array($metadata['normative_match'] ?? null) ? $metadata['normative_match'] : [];
        $decision = is_array($match['decision'] ?? null) ? $match['decision'] : [];
        $flags = is_array($item->flags) ? $item->flags : [];

        return ($decision['status'] ?? null) === 'review_priced'
            || in_array('requires_normative_review', $flags, true);
    }

    /**
     * @param array<string, int|float> $fullPricing
     */
    private function fullPricingExitCode(array $fullPricing): int
    {
        if (!(bool) $this->option('require-full-pricing')) {
            return self::SUCCESS;
        }

        if (
            (int) ($fullPricing['not_calculated_work_items'] ?? 0) > 0
            || (int) ($fullPricing['safe_norm_required_work_items'] ?? 0) > 0
            || (int) ($fullPricing['market_estimate_work_items'] ?? 0) > 0
            || (float) ($fullPricing['pricing_coverage'] ?? 0.0) < 1.0
        ) {
            $this->error('Есть позиции без полного нормативного расчета.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function nullableIntOption(string $key): ?int
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function topLimit(): int
    {
        return max(1, min((int) ($this->option('top') ?: 20), 100));
    }
}
