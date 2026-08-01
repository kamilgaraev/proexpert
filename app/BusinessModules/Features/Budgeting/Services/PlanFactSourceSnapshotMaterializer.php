<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQueryIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use DateTimeImmutable;
use InvalidArgumentException;

final class PlanFactSourceSnapshotMaterializer
{
    public const SOURCE_KIND = 'budgeting.plan_fact';

    public const REPORT_CODE = 'budget_plan_fact';

    public const SCHEMA_VERSION = '1.0.0';

    public const DRILL_COLUMN_ID = 'sources';

    public function materialize(
        string $snapshotId,
        ReportScope $scope,
        array $filters,
        array $report,
        array $drillsByKey,
        DateTimeImmutable $asOf,
        ?DateTimeImmutable $staleAt,
        BudgetingReportSourceClose $close,
        ?ReportQueryIdentity $reportQueryIdentity = null,
    ): ReportSourceSnapshotWrite {
        $identity = $this->identity($scope, $filters, $close->closeId, $reportQueryIdentity);
        $rows = $this->rows($snapshotId, $report['rows'] ?? []);
        $drillRows = $this->drillRows($snapshotId, $rows, $drillsByKey);
        $watermarks = $this->watermarks($report, $rows, $close);
        $sourceHash = $this->hash([
            'drill_rows' => array_map(static fn (ReportSourceSnapshotDrillRow $row): array => $row->payload, $drillRows),
            'rows' => array_map(static fn (ReportSourceSnapshotRow $row): array => $row->payload, $rows),
            'watermarks' => $watermarks,
        ]);
        $header = new ReportSourceSnapshotHeader(
            $snapshotId,
            self::SOURCE_KIND,
            self::REPORT_CODE,
            self::SCHEMA_VERSION,
            $scope,
            $identity->queryHash,
            $asOf,
            $sourceHash,
            $watermarks,
            $asOf,
            $staleAt,
            ReportSourceSnapshotStatus::WRITING,
            count($rows),
            count($drillRows),
            $this->hash(['pending' => $snapshotId]),
            null,
            null,
            $reportQueryIdentity?->projection,
            $reportQueryIdentity?->hash,
        );
        $snapshotHash = ReportSourceSnapshotIntegrity::hash($header, $rows, $drillRows);
        $header = new ReportSourceSnapshotHeader(
            $header->id,
            $header->sourceKind,
            $header->reportCode,
            $header->schemaVersion,
            $header->scope,
            $header->queryHash,
            $header->asOf,
            $header->sourceHash,
            $header->watermarks,
            $header->generatedAt,
            $header->staleAt,
            $header->status,
            $header->rowCount,
            $header->drillRowCount,
            $snapshotHash,
            null,
            null,
            $header->reportQueryIdentity,
            $header->reportQueryHash,
        );

        return new ReportSourceSnapshotWrite($header, $rows, $drillRows);
    }

    public function identity(
        ReportScope $scope,
        array $filters,
        string $sourceVersion,
        ?ReportQueryIdentity $reportQueryIdentity = null,
    ): ReportSourceSnapshotIdentity {
        return new ReportSourceSnapshotIdentity(
            self::SOURCE_KIND,
            self::REPORT_CODE,
            self::SCHEMA_VERSION,
            $scope,
            $this->hash([
                'filters' => $this->canonicalFilters($filters),
                'report_query_hash' => $reportQueryIdentity?->hash->value,
                'scope' => $scope->canonicalIdentity(),
            ]),
            $sourceVersion,
        );
    }

    private function rows(string $snapshotId, mixed $reportRows): array
    {
        if (! is_array($reportRows) || ! array_is_list($reportRows)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
        }

        $rows = [];
        foreach ($reportRows as $reportRow) {
            if (! is_array($reportRow) || ! is_string($reportRow['drill_down_key'] ?? null)) {
                throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
            }

            $payload = $this->redactRow($reportRow);
            $rows[] = ['key' => 'plan_fact:'.hash('sha256', CanonicalJson::encode($payload['group'])), 'payload' => $payload];
        }

        usort($rows, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        $keys = [];
        foreach ($rows as $ordinal => $row) {
            if (isset($keys[$row['key']])) {
                throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
            }
            $keys[$row['key']] = true;
            $rows[$ordinal] = new ReportSourceSnapshotRow(
                $snapshotId,
                $ordinal + 1,
                $row['key'],
                $row['payload'],
                $this->hash($row['payload']),
            );
        }

        return $rows;
    }

    private function drillRows(string $snapshotId, array $rows, array $drillsByKey): array
    {
        $drillRows = [];
        $drillsByReference = [];
        foreach ($drillsByKey as $drillKey => $items) {
            if (! is_string($drillKey)) {
                throw new InvalidArgumentException('plan_fact_source_snapshot_drill_invalid');
            }
            $drillsByReference[hash('sha256', $drillKey)] = $items;
        }

        foreach ($rows as $row) {
            $drillReference = $row->payload['drill']['key'];
            $items = $drillsByReference[$drillReference] ?? null;
            if (! is_array($items) || ! array_is_list($items)) {
                throw new InvalidArgumentException('plan_fact_source_snapshot_drill_invalid');
            }

            $payloads = array_map(fn (mixed $item): array => $this->redactDrill($item), $items);
            usort($payloads, static fn (array $left, array $right): int => $left['source_ref'] <=> $right['source_ref']);
            foreach ($payloads as $ordinal => $payload) {
                $drillRows[] = new ReportSourceSnapshotDrillRow(
                    $snapshotId,
                    $row->rowKey,
                    self::DRILL_COLUMN_ID,
                    $ordinal + 1,
                    $payload,
                    $this->hash($payload),
                );
            }
        }

        return $drillRows;
    }

    private function redactRow(array $row): array
    {
        $group = $row['group'] ?? null;
        if (! is_array($group)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
        }

        return [
            'actual_amount' => $this->number($row['actual_amount'] ?? null),
            'committed_amount' => $this->number($row['committed_amount'] ?? null),
            'currency' => $this->string($row['currency'] ?? null),
            'drill' => [
                'column_id' => self::DRILL_COLUMN_ID,
                'key' => hash('sha256', $this->string($row['drill_down_key'] ?? null)),
            ],
            'forecast_amount' => $this->number($row['forecast_amount'] ?? null),
            'group' => $this->scalarMap($group),
            'plan_amount' => $this->number($row['plan_amount'] ?? null),
            'risk_level' => $this->string($row['risk_level'] ?? null),
            'variance_amount' => $this->number($row['variance_amount'] ?? null),
            'variance_percent' => $this->nullableNumber($row['variance_percent'] ?? null),
        ];
    }

    private function redactDrill(mixed $item): array
    {
        if (! is_array($item) || ! is_string($item['source_type'] ?? null) || ! array_key_exists('source_id', $item)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_drill_invalid');
        }

        return [
            'amount' => $this->number($item['amount'] ?? null),
            'currency' => $this->string($item['currency'] ?? null),
            'date' => $this->string($item['date'] ?? null),
            'source_ref' => hash('sha256', $item['source_type'].'|'.(string) $item['source_id']),
            'source_type' => $this->string($item['source_type'] ?? null),
            'status' => $this->string($item['status'] ?? null),
            'variance_contribution' => $this->number($item['variance_contribution'] ?? null),
        ];
    }

    private function canonicalFilters(array $filters): array
    {
        $allowed = ['budget_article_id', 'budget_version_uuid', 'counterparty_id', 'currency', 'group_by', 'organization_id', 'period_end', 'period_start', 'project_id', 'responsibility_center_id', 'scenario_uuid'];
        $canonical = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $filters)) {
                $canonical[$key] = $filters[$key];
            }
        }

        return $canonical;
    }

    private function watermarks(array $report, array $rows, BudgetingReportSourceClose $close): array
    {
        $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
        $period = is_array($report['period'] ?? null) ? $report['period'] : [];
        $coverage = is_array($report['sources_coverage'] ?? null) ? $report['sources_coverage'] : [];
        $sources = [];
        foreach ($coverage as $source) {
            if (is_array($source) && is_string($source['source_type'] ?? null)) {
                $sources[$source['source_type']] = $source['included_aggregate_rows'] ?? null;
            }
        }
        ksort($sources, SORT_STRING);

        return [...[
            'budget_version_uuid' => $filters['budget_version_uuid'] ?? null,
            'period_end' => $period['to'] ?? null,
            'period_start' => $period['from'] ?? null,
            'row_count' => count($rows),
            'scenario_uuid' => $filters['scenario_uuid'] ?? null,
            'source_aggregate_rows' => $sources,
        ], ...$close->snapshotWatermarks()];
    }

    private function scalarMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || (! is_scalar($item) && $item !== null)) {
                throw new InvalidArgumentException('plan_fact_source_snapshot_rows_invalid');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('plan_fact_source_snapshot_invalid');
        }

        return $value;
    }

    private function number(mixed $value): float|int
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('plan_fact_source_snapshot_invalid');
        }

        return $value;
    }

    private function nullableNumber(mixed $value): float|int|null
    {
        return $value === null ? null : $this->number($value);
    }

    private function hash(array $value): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($value)));
    }
}
