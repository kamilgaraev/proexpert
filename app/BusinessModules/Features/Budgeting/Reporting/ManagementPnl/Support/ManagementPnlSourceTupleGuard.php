<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Support;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

final readonly class ManagementPnlSourceTupleGuard
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function assertReadyRun(
        int $organizationId,
        string $reportCode,
        string $snapshotKind,
        object $snapshot,
        PublishedReportDefinition $expected,
    ): object {
        $runs = $this->connection->table('report_runs')
            ->where('organization_id', $organizationId)
            ->where('report_code', $reportCode)
            ->where('status', 'ready')
            ->where('definition_hash', $expected->definitionHash->value)
            ->where('formula_version', $expected->definition->formulaVersion)
            ->where('source_schema_version', $expected->definition->sourceSchemaVersion)
            ->where('query_hash', $snapshot->query_hash)
            ->where('source_hash', $snapshot->source_hash)
            ->where('snapshot_kind', $snapshotKind)
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('id')
            ->get();
        if ($runs->count() !== 1) {
            throw new DomainException('management_pnl_source_run_tuple_ambiguous');
        }
        $run = $runs->first();
        if ($run === null
            || ! hash_equals(
                (string) $run->query_hash,
                hash('sha256', (string) $run->canonical_query_json),
            )) {
            throw new DomainException('management_pnl_source_run_tuple_unsealed');
        }

        return $run;
    }

    public function assertRequestedGroupCoverage(
        Collection $rows,
        array $requestedProjectIds,
        array $requestedCurrencies,
    ): array {
        if ($rows->contains(static fn (object $row): bool => $row->project_id === null)) {
            throw new DomainException('management_pnl_requested_group_coverage_gap');
        }
        $projects = $requestedProjectIds !== []
            ? $requestedProjectIds
            : $rows->pluck('project_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $currencies = $requestedCurrencies !== []
            ? $requestedCurrencies
            : $rows->pluck('currency')->map(static fn (mixed $currency): string => (string) $currency)
                ->unique()->values()->all();
        sort($projects, SORT_NUMERIC);
        sort($currencies, SORT_STRING);
        if ($projects === [] || $currencies === []) {
            throw new DomainException('management_pnl_requested_group_coverage_gap');
        }

        $coveredByCurrency = array_fill_keys($currencies, 0);
        foreach ($currencies as $currency) {
            foreach ($projects as $projectId) {
                if (! $rows->contains(
                    static fn (object $row): bool => (int) $row->project_id === $projectId
                        && (string) $row->currency === $currency,
                )) {
                    throw new DomainException('management_pnl_requested_group_coverage_gap');
                }
                $coveredByCurrency[$currency]++;
            }
        }

        return [
            'projects' => $projects,
            'currencies' => $currencies,
            'covered_by_currency' => $coveredByCurrency,
            'denominator_per_currency' => count($projects),
        ];
    }
}
