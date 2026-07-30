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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

final readonly class ProjectLaborCostManagementPnlComponentSource implements ManagementPnlComponentSource
{
    public function __construct(private ConnectionInterface $connection) {}

    public function componentCode(): string
    {
        return 'project_labor_cost';
    }

    public function snapshots(ReportScope $scope, ReportQuery $query): iterable
    {
        [$periodFrom, $periodTo, $scenario] = $this->scope($query);
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        $snapshots = $this->connection->table('project_labor_cost_report_snapshots')
            ->where('organization_id', $scope->organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('period_from', $periodFrom)
            ->where('period_to', $periodTo)
            ->where('as_of', $query->asOf->format('Y-m-d H:i:sP'))
            ->where('management_pnl_eligible', true)
            ->where('formula_version', 'labor-cost.v1')
            ->where('source_schema_version', 'project-labor-cost-source.v1')
            ->where('quality_status', 'complete')
            ->where('reconciliation_status', 'matched')
            ->orderBy('id')
            ->get();
        if ($snapshots->count() !== 1) {
            throw new DomainException('management_pnl_project_labor_cost_tuple_ambiguous');
        }
        $snapshot = $snapshots->first();
        if ($snapshot === null
            || preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->query_hash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->definition_hash) !== 1) {
            throw new DomainException('management_pnl_project_labor_cost_unsealed');
        }
        $currencies = $this->currencies($query);
        $rows = $this->connection->table('project_labor_cost_snapshot_rows')
            ->where('organization_id', $scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->whereBetween('work_date', [$periodFrom, $periodTo])
            ->when(
                $currencies !== [],
                static fn ($builder) => $builder->whereIn('currency', $currencies),
            )
            ->when(
                $scope->projectIds !== [],
                static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds),
            )
            ->orderBy('row_key')
            ->get();
        if ($rows->isEmpty()) {
            throw new DomainException('management_pnl_project_labor_cost_unavailable');
        }
        if ($rows->contains(static fn (object $row): bool => $row->cost === null || $row->currency === null)) {
            throw new DomainException('management_pnl_project_labor_cost_incomplete');
        }

        $persistedRowCount = $this->connection->table('project_labor_cost_snapshot_rows')
            ->where('organization_id', $scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->count();
        if ($persistedRowCount !== (int) $snapshot->row_count) {
            throw new DomainException('management_pnl_project_labor_cost_unsealed');
        }
        foreach ($rows->groupBy('currency')->sortKeys() as $currency => $currencyRows) {
            yield $this->component(
                $scope,
                $query,
                $snapshot,
                $currencyRows,
                (string) $currency,
                $periodFrom,
                $periodTo,
                $scenario,
                $scopeHash,
            );
        }
    }

    private function component(
        ReportScope $scope,
        ReportQuery $query,
        object $parent,
        Collection $rows,
        string $currency,
        string $periodFrom,
        string $periodTo,
        string $scenario,
        string $scopeHash,
    ): ManagementPnlComponentSnapshot {
        $identities = $rows->map(static fn (object $row): array => [
            'row_key' => (string) $row->row_key,
            'payload_hash' => hash('sha256', (string) $row->row_payload),
        ])->all();
        $hash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'parent_snapshot_id' => (string) $parent->id,
            'parent_source_hash' => (string) $parent->source_hash,
            'rows' => $identities,
        ])));
        $snapshotId = 'project-labor-'.substr($hash->value, 0, 40);
        $facts = $rows->map(fn (object $row): ManagementSourceFact => new ManagementSourceFact(
            sourceSnapshotId: $snapshotId,
            sourceType: $this->componentCode(),
            sourceRowKey: (string) $row->row_key,
            metricCode: 'direct_labor',
            organizationId: $scope->organizationId,
            projectId: (int) $row->project_id,
            responsibilityCenterId: null,
            budgetArticleId: null,
            period: (string) $row->work_date,
            scenario: $scenario,
            currency: $currency,
            amountMinor: $this->minor((string) $row->cost),
            sourceRefs: $this->json($row->source_refs),
        ))->all();

        return new ManagementPnlComponentSnapshot(
            componentCode: $this->componentCode(),
            snapshotId: $snapshotId,
            sourceHash: $hash,
            formulaVersion: 'time-tracking.labor-cost.v1',
            sourceSchemaVersion: 'approved-time-entry-reporting-fact.v1',
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            scenario: $scenario,
            currency: $currency,
            facts: $facts,
            scopeHash: $scopeHash,
            queryHash: $query->queryHash->value,
            definitionHash: $query->definition->definitionHash->value,
            asOf: $query->asOf->format(DATE_ATOM),
            rowCount: $rows->count(),
            coverageNumerator: count($facts),
            coverageDenominator: $rows->count(),
        );
    }

    private function scope(ReportQuery $query): array
    {
        $periodFrom = $query->filters->values['period_from'] ?? null;
        $periodTo = $query->filters->values['period_to'] ?? null;
        $scenarios = $query->filters->values['scenarios'] ?? null;
        if (! is_string($periodFrom) || ! is_string($periodTo) || $periodFrom > $periodTo
            || ! is_array($scenarios) || $scenarios !== ['actual']) {
            throw new DomainException('management_pnl_exact_scope_required');
        }

        return [$periodFrom, $periodTo, $scenarios[0]];
    }

    private function currencies(ReportQuery $query): array
    {
        $currencies = $query->filters->values['currencies'] ?? [];
        if (! is_array($currencies) || ! array_is_list($currencies)) {
            throw new DomainException('management_pnl_exact_scope_required');
        }
        foreach ($currencies as $currency) {
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new DomainException('management_pnl_exact_scope_required');
            }
        }

        return array_values(array_unique($currencies));
    }

    private function minor(string $amount): int
    {
        return (int) (string) BigDecimal::of($amount)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp);
    }

    private function json(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new DomainException('REPORT_SNAPSHOT_CORRUPT');
        }

        return $decoded;
    }
}
