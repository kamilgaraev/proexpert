<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Support;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Clock\SystemReportExecutionClock;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlSourceTuple;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use JsonException;
use Throwable;

final readonly class ManagementPnlSourceTupleGuard
{
    public function __construct(
        private ConnectionInterface $connection,
        private ReportExecutionClock $clock = new SystemReportExecutionClock,
    ) {}

    public function selectActiveReadyTuple(
        int $organizationId,
        string $reportCode,
        string $snapshotKind,
        PublishedReportDefinition $expected,
        ReportQuery $expectedQuery,
        string $sourceOfTruth,
        callable $snapshotResolver,
    ): ManagementPnlSourceTuple {
        if ($expectedQuery->scope->organizationId !== $organizationId
            || ! hash_equals(
                $expected->definitionHash->value,
                $expectedQuery->definition->definitionHash->value,
            )) {
            throw new DomainException('management_pnl_source_query_invalid');
        }

        $runs = $this->connection->table('report_runs')
            ->where('organization_id', $organizationId)
            ->where('report_code', $reportCode)
            ->where('status', 'ready')
            ->where('definition_hash', $expected->definitionHash->value)
            ->where('formula_version', $expected->definition->formulaVersion)
            ->where('source_schema_version', $expected->definition->sourceSchemaVersion)
            ->where('query_hash', $expectedQuery->queryHash->value)
            ->where('canonical_query_json', $expectedQuery->canonicalJson)
            ->where('snapshot_kind', $snapshotKind)
            ->where('expires_at', '>', $this->clock->now()->format('Y-m-d H:i:sP'))
            ->orderByDesc('ready_at')
            ->orderByDesc('id')
            ->get();

        foreach ($runs as $run) {
            if (! $this->matchesCanonicalQuery($run, $expectedQuery)) {
                continue;
            }
            $snapshot = $snapshotResolver($run);
            if (! is_object($snapshot)) {
                continue;
            }
            try {
                $this->assertRunSnapshotTuple(
                    $run,
                    $snapshotKind,
                    $snapshot,
                    $expected,
                    $sourceOfTruth,
                );
            } catch (DomainException) {
                continue;
            }

            return new ManagementPnlSourceTuple($run, $snapshot);
        }

        throw new DomainException('management_pnl_source_run_unavailable');
    }

    public function assertRunSnapshotTuple(
        object $run,
        string $snapshotKind,
        object $snapshot,
        PublishedReportDefinition $expected,
        string $sourceOfTruth,
    ): void {
        $sourceRefs = $this->canonicalSourceRefs(
            $this->jsonArray($snapshot->source_refs ?? null),
        );
        $watermarks = $sourceRefs === null
            ? null
            : array_column($sourceRefs, 'watermark', 'snapshot_id');
        $expectedProvenance = $sourceRefs === null ? null : [
            'source_of_truth' => $sourceOfTruth,
            'source_refs' => $sourceRefs,
            'source_hash' => (string) ($snapshot->source_hash ?? ''),
            'external_confirmation_role' => null,
        ];

        if ((string) $run->status !== 'ready'
            || new DateTimeImmutable((string) $run->expires_at) <= $this->clock->now()
            || ! hash_equals((string) $run->definition_hash, $expected->definitionHash->value)
            || ! hash_equals((string) $run->formula_version, $expected->definition->formulaVersion)
            || ! hash_equals((string) $run->source_schema_version, $expected->definition->sourceSchemaVersion)
            || ! hash_equals((string) $run->snapshot_kind, $snapshotKind)
            || ! hash_equals((string) $run->snapshot_id, (string) $snapshot->id)
            || ! hash_equals((string) $run->query_hash, (string) $snapshot->query_hash)
            || ! hash_equals((string) $run->source_hash, (string) $snapshot->source_hash)
            || ! $this->sameTimestamp($run->snapshot_generated_at ?? null, $snapshot->generated_at ?? null)
            || ! $this->sameTimestamp($run->snapshot_stale_at ?? null, $snapshot->stale_at ?? null)
            || (int) $run->row_count !== (int) ($snapshot->row_count ?? -1)
            || ! hash_equals((string) $run->freshness, (string) ($snapshot->freshness_status ?? ''))
            || (string) $run->freshness !== 'fresh'
            || ($snapshot->stale_at !== null
                && new DateTimeImmutable((string) $snapshot->stale_at) <= $this->clock->now())
            || $watermarks === null
            || ! $this->sameCanonicalJson($run->snapshot_watermarks ?? null, $watermarks)
            || $expectedProvenance === null
            || ! $this->sameCanonicalJson($run->provenance ?? null, $expectedProvenance)) {
            throw new DomainException('management_pnl_source_run_tuple_unsealed');
        }
    }

    private function matchesCanonicalQuery(
        object $run,
        ReportQuery $expectedQuery,
    ): bool {
        $canonical = (string) $run->canonical_query_json;

        return hash_equals($expectedQuery->canonicalJson, $canonical)
            && hash_equals($expectedQuery->queryHash->value, (string) $run->query_hash)
            && hash_equals((string) $run->query_hash, hash('sha256', $canonical));
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        try {
            return (new DateTimeImmutable((string) $left))->format('U.u')
                === (new DateTimeImmutable((string) $right))->format('U.u');
        } catch (Throwable) {
            return false;
        }
    }

    private function sameCanonicalJson(mixed $stored, array $expected): bool
    {
        $decoded = $this->jsonArray($stored);

        return $decoded !== null
            && hash_equals(CanonicalJson::encode($expected), CanonicalJson::encode($decoded));
    }

    private function jsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function canonicalSourceRefs(?array $sourceRefs): ?array
    {
        if ($sourceRefs === null || ! array_is_list($sourceRefs)) {
            return null;
        }

        $canonical = [];
        foreach ($sourceRefs as $sourceRef) {
            if (! is_array($sourceRef)
                || ! is_string($sourceRef['source'] ?? null)
                || ! is_string($sourceRef['snapshot_kind'] ?? null)
                || ! is_string($sourceRef['snapshot_id'] ?? null)
                || ! is_string($sourceRef['schema_version'] ?? null)
                || ! is_string($sourceRef['watermark'] ?? null)
                || ! is_int($sourceRef['row_count'] ?? null)
                || ! is_string($sourceRef['hash'] ?? null)) {
                return null;
            }
            try {
                $ref = new ReportSourceRef(
                    source: $sourceRef['source'],
                    snapshotKind: $sourceRef['snapshot_kind'],
                    snapshotId: $sourceRef['snapshot_id'],
                    schemaVersion: $sourceRef['schema_version'],
                    watermark: $sourceRef['watermark'],
                    rowCount: $sourceRef['row_count'],
                    hash: new Sha256Hash($sourceRef['hash']),
                );
            } catch (Throwable) {
                return null;
            }
            $canonical[] = [
                'source' => $ref->source,
                'snapshot_kind' => $ref->snapshotKind,
                'snapshot_id' => $ref->snapshotId,
                'schema_version' => $ref->schemaVersion,
                'watermark' => $ref->watermark,
                'row_count' => $ref->rowCount,
                'hash' => $ref->hash->value,
            ];
        }

        return $canonical;
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
