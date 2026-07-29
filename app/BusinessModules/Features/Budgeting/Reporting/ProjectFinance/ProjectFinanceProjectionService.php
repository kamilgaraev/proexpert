<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProjectFinanceProjectionService
{
    private const REPORT_SOURCE_SCOPE = [
        'project_margin' => 'project_margin',
        'budget_plan_fact' => 'plan_fact',
        'wip_completion_forecast' => 'wip_forecast',
    ];

    private const TTL = [
        'project_margin' => 'PT15M',
        'budget_plan_fact' => 'PT15M',
        'wip_completion_forecast' => 'PT10M',
    ];

    public function materializeMargin(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        return $this->materialize('project_margin', $scope, $query);
    }

    public function materializePlanFact(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        return $this->materialize('budget_plan_fact', $scope, $query);
    }

    public function materializeWip(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        $this->assertSingleActiveWipVersion($scope, $query);

        return $this->materialize('wip_completion_forecast', $scope, $query);
    }

    private function materialize(string $code, ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($query->definition->code !== $code || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }

        $sourceScope = $this->sourceScope($scope, $query, self::REPORT_SOURCE_SCOPE[$code]);
        $source = EpmDataMartSnapshot::query()
            ->forScope($scope->organizationId, self::REPORT_SOURCE_SCOPE[$code], $sourceScope->scopeHash())
            ->where('status', 'succeeded')
            ->where('formula_version', EpmDataMartPayloadProjector::FORMULA_VERSION)
            ->whereNull('superseded_at')
            ->where('generated_at', '<=', $query->asOf)
            ->where('stale_at', '>', $query->asOf)
            ->latest('generated_at')
            ->first();

        if (! $source instanceof EpmDataMartSnapshot) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $sourceFilters = is_array($source->filters) ? $source->filters : [];
        $this->assertSourceCoversQuery($sourceFilters, $query->filters->values);

        $sourceRows = $source->aggregates()
            ->where('organization_id', $scope->organizationId)
            ->where('report_scope', self::REPORT_SOURCE_SCOPE[$code])
            ->orderBy('aggregate_key')
            ->get();

        if ($sourceRows->isEmpty()) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $mappedRows = [];
        foreach ($sourceRows as $sourceRow) {
            $mappedRows[] = $this->mapRow($code, $sourceRow);
        }

        $generatedAt = new DateTimeImmutable((string) $source->generated_at);
        $snapshotId = (string) Str::ulid();
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'formula_version' => $query->definition->formulaVersion,
            'query_hash' => $query->queryHash->value,
            'rows' => $mappedRows,
            'source_snapshot_hash' => (string) $source->source_hash,
            'source_snapshot_id' => (string) $source->uuid,
        ])));
        $totals = $this->totals($code, $mappedRows);
        $staleAt = $generatedAt->add(new DateInterval(self::TTL[$code]));

        DB::transaction(function () use (
            $code,
            $scope,
            $query,
            $source,
            $sourceHash,
            $snapshotId,
            $generatedAt,
            $staleAt,
            $totals,
            $mappedRows,
        ): void {
            ProjectFinanceSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $scope->organizationId,
                'report_code' => $code,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'scope_hash' => hash('sha256', CanonicalJson::encode($scope->canonicalIdentity())),
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'source_snapshot_kind' => 'budgeting_epm_data_mart',
                'source_snapshot_id' => (string) $source->uuid,
                'source_snapshot_hash' => (string) $source->source_hash,
                'period_from' => $source->period_start,
                'period_to' => $source->period_end,
                'as_of' => $source->as_of_date,
                'budget_version_id' => $this->nullablePositiveInt($query->filters->values['budget_version_id'] ?? null),
                'forecast_version_id' => $this->nullablePositiveInt($query->filters->values['forecast_version_id'] ?? null),
                'closure_hash' => $code === 'budget_plan_fact' ? (string) $source->source_hash : null,
                'row_count' => count($mappedRows),
                'totals' => $totals,
                'source_refs' => [
                    [
                        'source' => 'budgeting_epm_data_mart',
                        'snapshot_kind' => 'budgeting_epm_data_mart',
                        'snapshot_id' => (string) $source->uuid,
                        'schema_version' => 'budgeting_epm_data_mart_v1',
                        'watermark' => $generatedAt->format('YmdHis'),
                        'row_count' => count($mappedRows),
                        'hash' => (string) $source->source_hash,
                    ],
                ],
                'quality_status' => 'complete',
                'coverage_numerator' => count($mappedRows),
                'coverage_denominator' => count($mappedRows),
                'generated_at' => $generatedAt,
                'stale_at' => $staleAt,
            ]);

            foreach (array_chunk($mappedRows, 500) as $chunk) {
                ProjectFinanceSnapshot::query()
                    ->findOrFail($snapshotId)
                    ->rows()
                    ->createMany(array_map(
                        static fn (array $row): array => [
                            ...$row,
                            'organization_id' => $scope->organizationId,
                            'report_code' => $code,
                        ],
                        $chunk,
                    ));
            }
        });

        return new ReportSnapshotRef(
            kind: 'budgeting_project_finance',
            id: $snapshotId,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: $query->definition->formulaVersion,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: [
                'query_hash' => $query->queryHash->value,
                'as_of' => (string) $source->as_of_date?->format('Y-m-d'),
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'budget_version_id' => $this->nullablePositiveInt($query->filters->values['budget_version_id'] ?? null) ?? 0,
                'forecast_version_id' => $this->nullablePositiveInt($query->filters->values['forecast_version_id'] ?? null) ?? 0,
                'source_generated_at' => $generatedAt->format(DATE_ATOM),
            ],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function mapRow(string $code, object $sourceRow): array
    {
        $dimensions = is_array($sourceRow->dimensions ?? null) ? $sourceRow->dimensions : [];
        $metrics = is_array($sourceRow->metrics ?? null) ? $sourceRow->metrics : [];
        $currency = mb_strtoupper(trim((string) ($sourceRow->currency ?? '')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('report_currency_source_missing');
        }

        $group = is_array($dimensions['group'] ?? null) ? $dimensions['group'] : [];
        $project = is_array($dimensions['project'] ?? null) ? $dimensions['project'] : [];
        $article = is_array($dimensions['budget_article'] ?? null) ? $dimensions['budget_article'] : [];
        $centre = is_array($dimensions['responsibility_center'] ?? null) ? $dimensions['responsibility_center'] : [];
        $period = $this->dateValue($group['month'] ?? $sourceRow->period_start ?? null);
        $base = [
            'row_key' => hash('sha256', (string) $sourceRow->aggregate_key),
            'project_id' => $this->nullablePositiveInt($sourceRow->project_id ?? $project['id'] ?? null),
            'project_name' => $this->nullableString($project['name'] ?? null),
            'responsibility_center_id' => $this->nullablePositiveInt($centre['id'] ?? $group['responsibility_center'] ?? null),
            'responsibility_center_name' => $this->nullableString($centre['name'] ?? null),
            'budget_article_id' => $this->nullablePositiveInt($article['id'] ?? $group['budget_article'] ?? null),
            'article_name' => $this->nullableString($article['name'] ?? $article['code'] ?? null),
            'wbs_id' => $this->nullablePositiveInt($group['stage'] ?? null),
            'wbs_code' => $this->nullableString($group['stage'] ?? null),
            'period' => $period,
            'currency' => $currency,
            'currency_source' => 'budgeting_epm_snapshot',
            'tax_basis' => 'unknown',
            'quality_status' => 'complete',
            'source_refs' => is_array($sourceRow->source_refs ?? null) ? $sourceRow->source_refs : [],
        ];

        return match ($code) {
            'project_margin' => [
                ...$base,
                'plan_revenue_minor' => $this->minor($metrics['plan']['revenue'] ?? null),
                'actual_revenue_minor' => $this->minor($metrics['actual']['revenue'] ?? null),
                'forecast_revenue_minor' => $this->minor($metrics['forecast']['revenue'] ?? null),
                'plan_cost_minor' => $this->minor($metrics['plan']['cost'] ?? null),
                'actual_cost_minor' => $this->minor($metrics['actual']['cost'] ?? null),
                'forecast_cost_minor' => $this->minor($metrics['forecast']['cost'] ?? null),
                'margin_minor' => $this->minor($metrics['actual']['gross_margin'] ?? null),
                'margin_percent' => $this->decimal($metrics['actual']['margin_percent'] ?? null),
            ],
            'budget_plan_fact' => [
                ...$base,
                'scenario' => $this->nullableString($dimensions['scenario']['code'] ?? null),
                'plan_minor' => $this->minor($metrics['plan_amount'] ?? null),
                'actual_minor' => $actual = $this->minor($metrics['actual_amount'] ?? null),
                'committed_minor' => $committed = $this->minor($metrics['committed_amount'] ?? null),
                'available_minor' => ($plan = $this->minor($metrics['plan_amount'] ?? null)) !== null
                    ? $plan - ($actual ?? 0) - ($committed ?? 0)
                    : null,
                'variance_minor' => $this->minor($metrics['variance_amount'] ?? null),
                'risk' => $this->nullableString($metrics['risk_level'] ?? null),
            ],
            'wip_completion_forecast' => [
                ...$base,
                'bac_minor' => $this->minor($metrics['metrics']['bac'] ?? $metrics['bac'] ?? null),
                'pv_minor' => $this->minor($metrics['metrics']['pv'] ?? $metrics['pv'] ?? null),
                'ev_minor' => $this->minor($metrics['metrics']['ev'] ?? $metrics['ev'] ?? null),
                'ac_minor' => $this->minor($metrics['metrics']['ac'] ?? $metrics['ac'] ?? null),
                'wip_minor' => $this->minor($metrics['metrics']['wip_total'] ?? $metrics['wip_total'] ?? null),
                'ctc_minor' => $this->minor($metrics['metrics']['ctc'] ?? $metrics['ctc'] ?? null),
                'eac_minor' => $this->minor($metrics['metrics']['eac'] ?? $metrics['eac'] ?? null),
                'forecast_variance_minor' => $this->minor($metrics['metrics']['forecast_variance'] ?? null),
                'spi' => $this->decimal($metrics['metrics']['spi'] ?? $metrics['spi'] ?? null),
                'cpi' => $this->decimal($metrics['metrics']['cpi'] ?? $metrics['cpi'] ?? null),
            ],
            default => throw new DomainException('report_code_invalid'),
        };
    }

    private function totals(string $code, array $rows): array
    {
        $fields = match ($code) {
            'project_margin' => [
                'plan_revenue_minor',
                'actual_revenue_minor',
                'forecast_revenue_minor',
                'plan_cost_minor',
                'actual_cost_minor',
                'forecast_cost_minor',
                'margin_minor',
            ],
            'budget_plan_fact' => ['plan_minor', 'actual_minor', 'committed_minor', 'available_minor', 'variance_minor'],
            'wip_completion_forecast' => ['bac_minor', 'pv_minor', 'ev_minor', 'ac_minor', 'wip_minor', 'ctc_minor', 'eac_minor', 'forecast_variance_minor'],
            default => [],
        };
        $totals = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $totals[$currency] ??= array_fill_keys($fields, 0);
            foreach ($fields as $field) {
                if ($row[$field] !== null) {
                    $totals[$currency][$field] += $row[$field];
                }
            }
        }
        ksort($totals);

        return $totals;
    }

    private function assertSingleActiveWipVersion(ReportScope $scope, ReportQuery $query): void
    {
        $builder = WipForecastVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('status', 'active');
        $projectIds = $query->filters->values['project_ids'] ?? $scope->projectIds;
        if (is_array($projectIds) && $projectIds !== []) {
            $builder->whereIn('project_id', $projectIds);
        }
        $versionIds = $query->filters->values['forecast_version_ids'] ?? [];
        if (is_array($versionIds) && $versionIds !== []) {
            $builder->whereIn('id', $versionIds);
        }
        if ($builder->count() !== 1) {
            throw new DomainException('wip_active_version_cardinality_invalid');
        }
    }

    private function assertSourceCoversQuery(array $sourceFilters, array $queryFilters): void
    {
        foreach ($queryFilters as $key => $value) {
            if (! array_key_exists($key, $sourceFilters)) {
                throw new DomainException('report_source_scope_mismatch');
            }
            if (CanonicalJson::encode($sourceFilters[$key]) !== CanonicalJson::encode($value)) {
                throw new DomainException('report_source_scope_mismatch');
            }
        }
    }

    private function sourceScope(ReportScope $scope, ReportQuery $query, string $reportScope): EpmDataMartScope
    {
        $filters = $query->filters->values;
        $periodStart = $filters['period_start'] ?? $filters['period_from'] ?? null;
        $periodEnd = $filters['period_end'] ?? $filters['period_to'] ?? null;
        $currencies = $filters['currencies'] ?? null;
        $currency = $filters['currency'] ?? (is_array($currencies) && count($currencies) === 1 ? $currencies[0] : null);
        $projectId = count($scope->projectIds) === 1 ? $scope->projectIds[0] : null;

        return EpmDataMartScope::fromInput($reportScope, [
            ...$filters,
            'organization_id' => $scope->organizationId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'as_of_date' => $query->asOf->format('Y-m-d'),
            'project_id' => $projectId,
            'currency' => $currency,
        ]);
    }

    private function minor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new DomainException('report_money_invalid');
        }
        $normalized = number_format((float) $value, 2, '.', '');
        if (abs((float) $value - (float) $normalized) > 0.000001) {
            throw new DomainException('report_money_minor_unit_loss');
        }
        $negative = str_starts_with($normalized, '-');
        [$whole, $fraction] = explode('.', ltrim($normalized, '-'));
        $minor = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    private function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 8, '.', '') : null;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format('Y-m-d');
    }
}
