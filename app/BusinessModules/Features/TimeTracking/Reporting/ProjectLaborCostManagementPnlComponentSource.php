<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use App\BusinessModules\Features\TimeTracking\Reporting\Models\ApprovedTimeEntryReportingFact;
use DomainException;

final readonly class ProjectLaborCostManagementPnlComponentSource implements ManagementPnlComponentSource
{
    public function componentCode(): string
    {
        return 'project_labor_cost';
    }

    public function snapshots(ReportScope $scope, ReportQuery $query): iterable
    {
        [$periodFrom, $periodTo, $scenario] = $this->scope($query);
        $rows = ApprovedTimeEntryReportingFact::query()
            ->where('organization_id', $scope->organizationId)
            ->whereBetween('work_date', [$periodFrom, $periodTo])
            ->where('approved_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->orderBy('id')
            ->get()
            ->groupBy('currency');
        if ($rows->isEmpty()) {
            throw new DomainException('management_pnl_project_labor_cost_unavailable');
        }

        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        foreach ($rows as $currency => $currencyRows) {
            $identities = $currencyRows->map(static fn (ApprovedTimeEntryReportingFact $row): array => [
                'id' => (int) $row->id,
                'hash' => (string) $row->source_hash,
            ])->all();
            $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($identities)));
            $snapshotId = 'project-labor-'.substr($sourceHash->value, 0, 40);
            $facts = [];
            foreach ($currencyRows as $row) {
                if ($row->cost_minor === null) {
                    continue;
                }
                $facts[] = new ManagementSourceFact(
                    sourceSnapshotId: $snapshotId,
                    sourceType: $this->componentCode(),
                    sourceRowKey: (string) $row->id,
                    metricCode: 'direct_labor',
                    organizationId: $scope->organizationId,
                    projectId: (int) $row->project_id,
                    responsibilityCenterId: null,
                    budgetArticleId: null,
                    period: $row->work_date->format('Y-m-d'),
                    scenario: $scenario,
                    currency: (string) $currency,
                    amountMinor: (int) $row->cost_minor,
                    sourceRefs: [[
                        'type' => 'approved_time_entry',
                        'id' => (string) $row->time_entry_id,
                        'version' => 1,
                        'hash' => (string) $row->source_hash,
                    ]],
                );
            }

            yield new ManagementPnlComponentSnapshot(
                componentCode: $this->componentCode(),
                snapshotId: $snapshotId,
                sourceHash: $sourceHash,
                formulaVersion: 'time-tracking.labor-cost.v1',
                sourceSchemaVersion: 'approved-time-entry-reporting-fact.v1',
                periodFrom: $periodFrom,
                periodTo: $periodTo,
                scenario: $scenario,
                currency: (string) $currency,
                facts: $facts,
                scopeHash: $scopeHash,
                queryHash: $query->queryHash->value,
                definitionHash: $query->definition->definitionHash->value,
                asOf: $query->asOf->format(DATE_ATOM),
                rowCount: $currencyRows->count(),
                coverageNumerator: count($facts),
                coverageDenominator: $currencyRows->count(),
                warnings: count($facts) === $currencyRows->count() ? [] : ['approved_time_entry_rate_missing'],
            );
        }
    }

    private function scope(ReportQuery $query): array
    {
        $periodFrom = $query->filters->values['period_from'] ?? null;
        $periodTo = $query->filters->values['period_to'] ?? null;
        $scenarios = $query->filters->values['scenarios'] ?? null;
        if (! is_string($periodFrom) || ! is_string($periodTo)
            || ! is_array($scenarios) || count($scenarios) !== 1 || ! is_string($scenarios[0])) {
            throw new DomainException('management_pnl_exact_scope_required');
        }

        return [$periodFrom, $periodTo, $scenarios[0]];
    }
}
