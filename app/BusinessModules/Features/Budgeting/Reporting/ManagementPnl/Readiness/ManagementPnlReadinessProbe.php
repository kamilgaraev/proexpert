<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlReadinessSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlComponentSet;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlPolicy;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\BudgetPlanFactManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectMarginManagementPnlComponentSource;
use App\BusinessModules\Features\TimeTracking\Reporting\Models\ApprovedTimeEntryReportingFact;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Models\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessManagementPnlComponentSource;
use DomainException;

final readonly class ManagementPnlReadinessProbe
{
    public function __construct(
        private ProjectMarginManagementPnlComponentSource $margin,
        private BudgetPlanFactManagementPnlComponentSource $planFact,
        private ProjectLaborCostManagementPnlComponentSource $labor,
        private PayrollReadinessManagementPnlComponentSource $payroll,
        private ManagementPnlComponentSet $componentSet,
    ) {}

    public function inspect(ReportScope $scope, ReportQuery $query): ManagementPnlReadinessSnapshot
    {
        $filters = $query->filters->values;
        $periodFrom = (string) ($filters['period_from'] ?? '');
        $periodTo = (string) ($filters['period_to'] ?? '');
        $scenario = is_array($filters['scenarios'] ?? null) && count($filters['scenarios']) === 1
            ? (string) $filters['scenarios'][0]
            : '';
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));

        $financeFacts = (int) ProjectFinanceSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('report_code', ['project_margin', 'budget_plan_fact'])
            ->whereDate('period_from', $periodFrom)
            ->whereDate('period_to', $periodTo)
            ->where('scope_hash', $scopeHash)
            ->where('generated_at', '<=', $query->asOf)
            ->sum('row_count');
        $laborFacts = ApprovedTimeEntryReportingFact::query()
            ->where('organization_id', $scope->organizationId)
            ->whereBetween('work_date', [$periodFrom, $periodTo])
            ->where('approved_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->count();
        $payrollFacts = (int) PayrollReadinessSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->whereDate('period_from', '>=', $periodFrom)
            ->whereDate('period_to', '<=', $periodTo)
            ->where('locked_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn ($builder) => $builder
                ->where(static fn ($projects) => $projects
                    ->whereNull('project_id')
                    ->orWhereIn('project_id', $scope->projectIds)))
            ->sum('row_count');
        $factCount = $financeFacts + $laborFacts + $payrollFacts;
        $hasPolicy = ManagementPnlPolicy::query()
            ->where('organization_id', $scope->organizationId)
            ->where('status', 'active')
            ->exists();

        $components = [];
        $sealed = false;
        try {
            foreach ([$this->margin, $this->planFact, $this->labor, $this->payroll] as $source) {
                foreach ($source->snapshots($scope, $query) as $component) {
                    $components[] = $component;
                }
            }
            $this->componentSet->validate(
                $components,
                $scope->organizationId,
                $scope->projectIds,
                $periodFrom,
                $periodTo,
                $scenario,
                $scopeHash,
                $query->asOf->format(DATE_ATOM),
                is_array($filters['currencies'] ?? null) ? $filters['currencies'] : [],
            );
            $sealed = true;
            foreach ($components as $component) {
                if ($component->coverageNumerator !== $component->coverageDenominator) {
                    $sealed = false;
                    break;
                }
            }
        } catch (DomainException) {
            $sealed = false;
        }

        $currencies = array_values(array_unique(array_map(
            static fn ($component): string => $component->currency,
            $components,
        )));
        sort($currencies, SORT_STRING);
        $financeSnapshotIds = array_values(array_unique(array_map(
            static fn ($component): string => $component->snapshotId,
            array_filter($components, static fn ($component): bool => in_array(
                $component->componentCode,
                ['project_margin', 'budget_plan_fact'],
                true,
            )),
        )));
        $dimensionRows = $financeSnapshotIds === []
            ? collect()
            : ProjectFinanceRow::query()
                ->where('organization_id', $scope->organizationId)
                ->whereIn('snapshot_id', $financeSnapshotIds)
                ->get(['project_id', 'responsibility_center_id', 'budget_article_id']);

        return new ManagementPnlReadinessSnapshot(
            $factCount,
            $hasPolicy,
            $sealed,
            $currencies,
            $factCount === 0 || $scenario === '' ? [] : [$scenario],
            $this->ids($dimensionRows->pluck('project_id')->all()),
            $this->ids($dimensionRows->pluck('responsibility_center_id')->all()),
            $this->ids($dimensionRows->pluck('budget_article_id')->all()),
        );
    }

    /** @return list<int> */
    private function ids(array $values): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($values))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
