<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Support;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use JsonException;

final readonly class ManagementPnlSourceTupleGuard
{
    public function __construct(
        private ConnectionInterface $connection,
        private ReportExecutionClock $clock = new SystemReportExecutionClock,
    ) {}

    public function selectActiveReadyRun(
        int $organizationId,
        string $reportCode,
        string $snapshotKind,
        PublishedReportDefinition $expected,
        ReportScope $scope,
        string $periodFrom,
        string $periodTo,
        DateTimeImmutable $asOf,
    ): object {
        $runs = $this->connection->table('report_runs')
            ->where('organization_id', $organizationId)
            ->where('report_code', $reportCode)
            ->where('status', 'ready')
            ->where('definition_hash', $expected->definitionHash->value)
            ->where('formula_version', $expected->definition->formulaVersion)
            ->where('source_schema_version', $expected->definition->sourceSchemaVersion)
            ->where('snapshot_kind', $snapshotKind)
            ->where('expires_at', '>', $this->clock->now()->format('Y-m-d H:i:sP'))
            ->orderByDesc('ready_at')
            ->orderByDesc('id')
            ->get();

        foreach ($runs as $run) {
            if ($this->matchesCanonicalQuery(
                $run,
                $expected,
                $scope,
                $periodFrom,
                $periodTo,
                $asOf,
            )) {
                return $run;
            }
        }

        throw new DomainException('management_pnl_source_run_unavailable');
    }

    public function assertRunSnapshotTuple(
        object $run,
        string $snapshotKind,
        object $snapshot,
        PublishedReportDefinition $expected,
    ): void {
        if ((string) $run->status !== 'ready'
            || new DateTimeImmutable((string) $run->expires_at) <= $this->clock->now()
            || ! hash_equals((string) $run->definition_hash, $expected->definitionHash->value)
            || ! hash_equals((string) $run->formula_version, $expected->definition->formulaVersion)
            || ! hash_equals((string) $run->source_schema_version, $expected->definition->sourceSchemaVersion)
            || ! hash_equals((string) $run->snapshot_kind, $snapshotKind)
            || ! hash_equals((string) $run->snapshot_id, (string) $snapshot->id)
            || ! hash_equals((string) $run->query_hash, (string) $snapshot->query_hash)
            || ! hash_equals((string) $run->source_hash, (string) $snapshot->source_hash)) {
            throw new DomainException('management_pnl_source_run_tuple_unsealed');
        }
    }

    private function matchesCanonicalQuery(
        object $run,
        PublishedReportDefinition $expected,
        ReportScope $scope,
        string $periodFrom,
        string $periodTo,
        DateTimeImmutable $asOf,
    ): bool {
        $canonical = (string) $run->canonical_query_json;
        if (! hash_equals((string) $run->query_hash, hash('sha256', $canonical))) {
            return false;
        }
        try {
            $query = json_decode($canonical, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (! is_array($query)
            || array_keys($query) !== ['as_of', 'comparison', 'definition_hash', 'filters', 'locale', 'scope']
            || $query['as_of'] !== $asOf->format(DATE_ATOM)
            || $query['comparison'] !== []
            || $query['definition_hash'] !== $expected->definitionHash->value
            || ! is_array($query['scope'])
            || CanonicalJson::encode($query['scope']) !== CanonicalJson::encode($scope->canonicalIdentity())
            || ! is_array($query['filters'])
            || CanonicalJson::encode($query) !== $canonical) {
            return false;
        }
        $filters = $query['filters'];
        if (array_key_exists('period_from', $filters) && $filters['period_from'] !== $periodFrom) {
            return false;
        }
        if (array_key_exists('period_to', $filters) && $filters['period_to'] !== $periodTo) {
            return false;
        }

        return true;
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
