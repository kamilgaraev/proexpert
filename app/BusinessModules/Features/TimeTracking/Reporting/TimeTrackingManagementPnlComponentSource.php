<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class TimeTrackingManagementPnlComponentSource implements ManagementPnlComponentSource
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function componentCode(): string
    {
        return 'project_labor_cost';
    }

    public function snapshots(ReportScope $scope, ReportQuery $query): iterable
    {
        $scenario = $this->scenario($query);
        $snapshot = $this->connection->table('project_labor_cost_report_snapshots')
            ->where('organization_id', $scope->organizationId)
            ->where('generated_at', '<=', $query->asOf->format('Y-m-d H:i:sP'))
            ->where('stale_at', '>', $query->asOf->format('Y-m-d H:i:sP'))
            ->orderByDesc('generated_at')
            ->first();
        if ($snapshot === null) {
            throw new DomainException('management_pnl_component_unavailable');
        }

        yield from $this->componentSnapshots($scope, $snapshot, $scenario);
    }

    public function snapshot(
        ReportScope $scope,
        ReportSnapshotRef $snapshot,
    ): ManagementPnlComponentSnapshot {
        if ($snapshot->kind !== 'project_labor_cost'
            || $snapshot->scope->organizationId !== $scope->organizationId) {
            throw new DomainException('management_pnl_component_scope_invalid');
        }
        $record = $this->connection->table('project_labor_cost_report_snapshots')
            ->where('organization_id', $scope->organizationId)
            ->where('id', $snapshot->id)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->first();
        if ($record === null) {
            throw new DomainException('management_pnl_component_unavailable');
        }
        $components = iterator_to_array($this->componentSnapshots($scope, $record, 'actual'), false);
        if (count($components) !== 1) {
            throw new DomainException('management_pnl_component_currency_ambiguous');
        }

        return $components[0];
    }

    private function componentSnapshots(ReportScope $scope, object $snapshot, string $scenario): iterable
    {
        $rows = $this->connection->table('project_labor_cost_snapshot_rows')
            ->where('organization_id', $scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->whereNotNull('cost')
            ->whereNotNull('currency')
            ->when(
                $scope->projectIds !== [],
                static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds),
            )
            ->orderBy('row_key')
            ->get()
            ->groupBy('currency');
        if ($rows->isEmpty()) {
            throw new DomainException('management_pnl_component_unavailable');
        }

        foreach ($rows as $currency => $currencyRows) {
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new DomainException('management_pnl_component_currency_invalid');
            }
            $facts = [];
            foreach ($currencyRows as $row) {
                $facts[] = new ManagementSourceFact(
                    sourceSnapshotId: (string) $snapshot->id,
                    sourceType: 'project_labor_cost',
                    sourceRowKey: (string) $row->row_key,
                    metricCode: 'approved_labor_cost',
                    organizationId: $scope->organizationId,
                    projectId: (int) $row->project_id,
                    responsibilityCenterId: null,
                    budgetArticleId: null,
                    period: (string) $row->work_date,
                    scenario: $scenario,
                    currency: $currency,
                    amountMinor: $this->minor((string) $row->cost),
                    sourceRefs: $this->json($row->source_refs),
                );
            }

            yield new ManagementPnlComponentSnapshot(
                componentCode: 'project_labor_cost',
                snapshotId: (string) $snapshot->id,
                sourceHash: new Sha256Hash((string) $snapshot->source_hash),
                formulaVersion: (string) $snapshot->formula_version,
                sourceSchemaVersion: (string) $snapshot->source_schema_version,
                periodFrom: (string) $currencyRows->min('work_date'),
                periodTo: (string) $currencyRows->max('work_date'),
                scenario: $scenario,
                currency: $currency,
                facts: $facts,
            );
        }
    }

    private function scenario(ReportQuery $query): string
    {
        $scenarios = $query->filters->values['scenarios'] ?? [];
        if (!is_array($scenarios) || count($scenarios) !== 1 || !is_string($scenarios[0])) {
            throw new DomainException('management_pnl_scenario_required');
        }

        return $scenarios[0];
    }

    private function minor(string $amount): int
    {
        return (int) (string) BigDecimal::of($amount)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp);
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            throw new DomainException('REPORT_SNAPSHOT_CORRUPT');
        }

        CanonicalJson::encode($decoded);

        return $decoded;
    }
}
