<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DomainException;

final readonly class ProjectFinanceManagementPnlComponentSource implements ManagementPnlComponentSource
{
    public function componentCode(): string
    {
        return 'project_finance';
    }

    public function snapshots(ReportScope $scope, ReportQuery $query): iterable
    {
        $scenarios = $query->filters->values['scenarios'] ?? [];
        if (!is_array($scenarios) || count($scenarios) !== 1 || !is_string($scenarios[0])) {
            throw new DomainException('management_pnl_scenario_required');
        }
        $scenario = $scenarios[0];
        $periodFrom = $query->filters->values['period_from'] ?? null;
        $periodTo = $query->filters->values['period_to'] ?? null;
        if (!is_string($periodFrom) || !is_string($periodTo)) {
            throw new DomainException('management_pnl_exact_scope_required');
        }
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));

        foreach (['project_margin', 'budget_plan_fact'] as $code) {
            $snapshot = ProjectFinanceSnapshot::query()
                ->where('organization_id', $scope->organizationId)
                ->where('report_code', $code)
                ->where('scope_hash', $scopeHash)
                ->whereDate('period_from', $periodFrom)
                ->whereDate('period_to', $periodTo)
                ->whereDate('as_of', $query->asOf->format('Y-m-d'))
                ->where('generated_at', '<=', $query->asOf)
                ->latest('generated_at')
                ->first();
            if (!$snapshot instanceof ProjectFinanceSnapshot || $snapshot->stale_at <= $query->asOf) {
                throw new DomainException('management_pnl_component_unavailable');
            }
            $rows = ProjectFinanceRow::query()
                ->where('organization_id', $scope->organizationId)
                ->where('snapshot_id', $snapshot->id)
                ->when($code === 'budget_plan_fact', static fn ($builder) => $builder->where('direction', 'expense'))
                ->when($scope->projectIds !== [], static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds))
                ->orderBy('row_key')
                ->get()
                ->groupBy('currency');
            foreach ($rows as $currency => $currencyRows) {
                $facts = [];
                foreach ($currencyRows as $row) {
                    $metric = $code === 'project_margin'
                        ? 'actual_revenue'
                        : ($scenario === 'actual' ? 'actual_non_labor_cost' : 'planned_non_labor_cost');
                    $amount = $code === 'project_margin'
                        ? $row->actual_revenue_minor
                        : ($scenario === 'actual' ? $row->actual_minor : $row->plan_minor);
                    if ($amount === null) {
                        continue;
                    }
                    $facts[] = new ManagementSourceFact(
                        sourceSnapshotId: (string) $snapshot->id,
                        sourceType: $code,
                        sourceRowKey: (string) $row->row_key,
                        metricCode: $metric,
                        organizationId: $scope->organizationId,
                        projectId: $row->project_id === null ? null : (int) $row->project_id,
                        responsibilityCenterId: $row->responsibility_center_id === null ? null : (int) $row->responsibility_center_id,
                        budgetArticleId: $row->budget_article_id === null ? null : (int) $row->budget_article_id,
                        period: $row->period?->format('Y-m-d') ?? (string) $snapshot->period_to?->format('Y-m-d'),
                        scenario: $scenario,
                        currency: (string) $currency,
                        amountMinor: (int) $amount,
                        sourceRefs: [
                            [
                                'type' => $code,
                                'id' => (string) $row->row_key,
                                'snapshot_id' => (string) $snapshot->id,
                            ],
                        ],
                    );
                }
                yield new ManagementPnlComponentSnapshot(
                    componentCode: $code,
                    snapshotId: (string) $snapshot->id,
                    sourceHash: new Sha256Hash((string) $snapshot->source_hash),
                    formulaVersion: (string) $snapshot->formula_version,
                    sourceSchemaVersion: (string) $snapshot->source_schema_version,
                    periodFrom: (string) $snapshot->period_from?->format('Y-m-d'),
                    periodTo: (string) $snapshot->period_to?->format('Y-m-d'),
                    scenario: $scenario,
                    currency: (string) $currency,
                    facts: $facts,
                );
            }
        }
    }
}
