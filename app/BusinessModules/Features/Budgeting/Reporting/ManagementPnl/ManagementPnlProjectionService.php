<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlPolicy;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlSnapshot;
use DateInterval;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ManagementPnlProjectionService
{
    public function __construct(
        private iterable $componentSources,
        private ManagementPnlComponentSet $componentSet,
    ) {}

    public function materialize(
        ReportScope $scope,
        ReportQuery $query,
        ManagementAccountingPolicy $policy,
    ): ReportSnapshotRef {
        if ($query->definition->code !== 'management_pnl'
            || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }
        $policyRecord = ManagementPnlPolicy::query()
            ->where('organization_id', $scope->organizationId)
            ->where('status', 'active')
            ->where('version', $policy->version())
            ->first();
        if (! $policyRecord instanceof ManagementPnlPolicy) {
            throw new DomainException('management_pnl_active_policy_missing');
        }

        $components = [];
        foreach ($this->componentSources as $source) {
            if (! $source instanceof ManagementPnlComponentSource) {
                throw new DomainException('management_pnl_component_source_invalid');
            }
            foreach ($source->snapshots($scope, $query) as $component) {
                if (! $component instanceof ManagementPnlComponentSnapshot) {
                    throw new DomainException('management_pnl_component_snapshot_invalid');
                }
                $components[] = $component;
            }
        }
        $filters = $query->filters->values;
        $this->assertSupportedFilters($filters);
        $periodFrom = $filters['period_from'] ?? null;
        $periodTo = $filters['period_to'] ?? null;
        $scenarios = $filters['scenarios'] ?? null;
        if (! is_string($periodFrom) || ! is_string($periodTo)
            || ! is_array($scenarios) || count($scenarios) !== 1 || ! is_string($scenarios[0])) {
            throw new DomainException('management_pnl_exact_scope_required');
        }
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        $requiredCurrencies = array_values(array_unique(array_map(
            static fn (mixed $currency): string => mb_strtoupper((string) $currency),
            is_array($filters['currencies'] ?? null) ? $filters['currencies'] : [],
        )));
        $components = $this->componentSet->validate(
            $components,
            $scope->organizationId,
            $scope->projectIds,
            $periodFrom,
            $periodTo,
            $scenarios[0],
            $scopeHash,
            $query->asOf->format(DATE_ATOM),
            $requiredCurrencies,
        );

        $facts = [];
        $componentIdentities = [];
        $coverageNumerator = 0;
        $coverageDenominator = 0;
        $warnings = [];
        foreach ($components as $component) {
            $this->assertComponentScope($component, $query);
            $componentIdentities[] = [
                'component_code' => $component->componentCode,
                'snapshot_id' => $component->snapshotId,
                'source_hash' => $component->sourceHash->value,
                'formula_version' => $component->formulaVersion,
                'source_schema_version' => $component->sourceSchemaVersion,
                'period_from' => $component->periodFrom,
                'period_to' => $component->periodTo,
                'scenario' => $component->scenario,
                'currency' => $component->currency,
                'scope_hash' => $component->scopeHash,
                'query_hash' => $component->queryHash,
                'definition_hash' => $component->definitionHash,
                'as_of' => $component->asOf,
                'row_count' => $component->rowCount,
                'coverage_numerator' => $component->coverageNumerator,
                'coverage_denominator' => $component->coverageDenominator,
                'warnings' => $component->warnings,
            ];
            $coverageNumerator += (int) $component->coverageNumerator;
            $coverageDenominator += (int) $component->coverageDenominator;
            foreach ($component->warnings as $warning) {
                $warnings[] = [
                    'component_code' => $component->componentCode,
                    'currency' => $component->currency,
                    'code' => (string) $warning,
                ];
            }
            foreach ($component->facts as $fact) {
                if (! $this->matchesFactFilters($fact, $filters)) {
                    continue;
                }
                $identity = $fact->identity();
                if (isset($facts[$identity])) {
                    throw new DomainException('management_pnl_source_fact_duplicate');
                }
                $facts[$identity] = $fact;
            }
        }

        $allocated = [];
        foreach ($facts as $fact) {
            $classification = $policy->classify($fact);
            if ($classification->category === 'direct_labor' && $fact->sourceType !== 'project_labor_cost') {
                throw new DomainException('management_pnl_direct_labor_source_invalid');
            }
            $allocations = $policy->allocate($fact);
            $remaining = $fact->amountMinor;
            foreach ($allocations as $index => $allocation) {
                $amount = $index === array_key_last($allocations)
                    ? $remaining
                    : $this->halfUp($fact->amountMinor, $allocation->basisPoints);
                $remaining -= $amount;
                $key = implode(':', [
                    $fact->period,
                    $scope->organizationId,
                    $allocation->projectId ?? 0,
                    $allocation->responsibilityCenterId ?? 0,
                    $allocation->budgetArticleId ?? 0,
                    $fact->currency,
                    $fact->scenario,
                ]);
                $allocated[$key] ??= [
                    'organization_id' => $scope->organizationId,
                    'project_id' => $allocation->projectId,
                    'responsibility_center_id' => $allocation->responsibilityCenterId,
                    'budget_article_id' => $allocation->budgetArticleId,
                    'period' => $fact->period,
                    'scenario' => $fact->scenario,
                    'currency' => $fact->currency,
                    'revenue_minor' => 0,
                    'direct_cost_minor' => 0,
                    'operating_expense_minor' => 0,
                    'source_refs' => [],
                ];
                $field = match ($classification->category) {
                    'revenue' => 'revenue_minor',
                    'direct_non_labor_cost', 'direct_labor' => 'direct_cost_minor',
                    'operating_expense' => 'operating_expense_minor',
                };
                $allocated[$key][$field] += $amount;
                $allocated[$key]['source_refs'][] = [
                    'type' => $fact->sourceType,
                    'id' => $fact->sourceRowKey,
                    'snapshot_id' => $fact->sourceSnapshotId,
                    'metric_code' => $fact->metricCode,
                    'classification' => $classification->category,
                    'allocation_basis_points' => $allocation->basisPoints,
                    'policy_id' => (int) $policyRecord->id,
                    'policy_version' => $policy->version(),
                    'sources' => $fact->sourceRefs,
                ];
            }
        }

        $rows = [];
        foreach ($allocated as $key => $row) {
            $grossMargin = $row['revenue_minor'] - $row['direct_cost_minor'];
            $operatingResult = $grossMargin - $row['operating_expense_minor'];
            $rows[] = [
                ...$row,
                'row_key' => hash('sha256', $key),
                'gross_margin_minor' => $grossMargin,
                'operating_result_minor' => $operatingResult,
                'gross_margin_percent' => $row['revenue_minor'] === 0
                    ? null
                    : number_format($grossMargin / $row['revenue_minor'] * 100, 8, '.', ''),
                'policy_version' => $policy->version(),
            ];
        }
        if ($rows === []) {
            throw new DomainException('management_pnl_component_facts_missing');
        }
        usort($componentIdentities, static fn (array $left, array $right): int => strcmp(
            $left['component_code'].$left['currency'].$left['snapshot_id'],
            $right['component_code'].$right['currency'].$right['snapshot_id'],
        ));

        $snapshotId = (string) Str::ulid();
        $generatedAt = $query->asOf;
        $staleAt = $generatedAt->add(new DateInterval('PT15M'));
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'components' => $componentIdentities,
            'policy_hash' => (string) $policyRecord->policy_hash,
            'query_hash' => $query->queryHash->value,
        ])));
        $totals = $this->totals($rows);
        $qualityStatus = $coverageNumerator === $coverageDenominator ? 'complete' : 'partial';

        DB::transaction(function () use (
            $scope,
            $scopeHash,
            $query,
            $policy,
            $policyRecord,
            $snapshotId,
            $sourceHash,
            $componentIdentities,
            $generatedAt,
            $staleAt,
            $rows,
            $totals,
            $coverageNumerator,
            $coverageDenominator,
            $qualityStatus,
            $warnings,
        ): void {
            $snapshot = ManagementPnlSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $scope->organizationId,
                'policy_id' => $policyRecord->id,
                'policy_version' => $policy->version(),
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'scope_hash' => $scopeHash,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'component_snapshots' => $componentIdentities,
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $staleAt,
                'row_count' => count($rows),
                'totals' => $totals,
                'coverage_numerator' => $coverageNumerator,
                'coverage_denominator' => $coverageDenominator,
                'quality_status' => $qualityStatus,
                'warnings' => $warnings,
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $snapshot->rows()->createMany($chunk);
            }
        });

        return new ReportSnapshotRef(
            kind: 'management_pnl',
            id: $snapshotId,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: $query->definition->formulaVersion,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: [
                'query_hash' => $query->queryHash->value,
                'as_of' => $query->asOf->format(DATE_ATOM),
                'policy_id' => (int) $policyRecord->id,
                'policy_version' => $policy->version(),
                'component_source_hash' => $sourceHash->value,
            ],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function assertComponentScope(ManagementPnlComponentSnapshot $component, ReportQuery $query): void
    {
        $filters = $query->filters->values;
        if (($filters['period_from'] ?? $component->periodFrom) !== $component->periodFrom
            || ($filters['period_to'] ?? $component->periodTo) !== $component->periodTo
            || (isset($filters['scenarios']) && ! in_array($component->scenario, (array) $filters['scenarios'], true))
            || (isset($filters['currencies']) && ! in_array($component->currency, (array) $filters['currencies'], true))) {
            throw new DomainException('management_pnl_component_scope_mismatch');
        }
        foreach ($component->facts as $fact) {
            if ($fact->scenario !== $component->scenario || $fact->currency !== $component->currency
                || $fact->period < $component->periodFrom || $fact->period > $component->periodTo) {
                throw new DomainException('management_pnl_component_fact_scope_mismatch');
            }
        }
    }

    private function halfUp(int $amount, int $basisPoints): int
    {
        $sign = $amount < 0 ? -1 : 1;

        return $sign * intdiv(abs($amount) * $basisPoints + 5000, 10000);
    }

    private function matchesFactFilters(ManagementSourceFact $fact, array $filters): bool
    {
        foreach ([
            'project_ids' => $fact->projectId,
            'responsibility_center_ids' => $fact->responsibilityCenterId,
            'budget_article_ids' => $fact->budgetArticleId,
            'currencies' => $fact->currency,
            'scenarios' => $fact->scenario,
        ] as $filter => $actual) {
            if (isset($filters[$filter])
                && ! in_array($actual, is_array($filters[$filter]) ? $filters[$filter] : [$filters[$filter]], true)) {
                return false;
            }
        }

        return true;
    }

    private function assertSupportedFilters(array $filters): void
    {
        $supported = array_fill_keys([
            'period_from',
            'period_to',
            'project_ids',
            'responsibility_center_ids',
            'budget_article_ids',
            'currencies',
            'scenarios',
        ], true);
        foreach (array_keys($filters) as $filter) {
            if (! isset($supported[$filter])) {
                throw new DomainException('report_filter_not_sealed');
            }
        }
    }

    private function totals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $totals[$currency] ??= [
                'revenue_minor' => 0,
                'direct_cost_minor' => 0,
                'gross_margin_minor' => 0,
                'operating_expense_minor' => 0,
                'operating_result_minor' => 0,
            ];
            foreach (array_keys($totals[$currency]) as $field) {
                $totals[$currency][$field] += $row[$field];
            }
        }
        ksort($totals);

        return $totals;
    }
}
