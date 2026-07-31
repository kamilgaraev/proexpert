<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use DateTimeImmutable;
use InvalidArgumentException;

final class ProjectMarginSourceSnapshotMaterializer
{
    public const SOURCE_KIND = 'budgeting.project_margin';
    public const REPORT_CODE = 'project_margin';
    public const SCHEMA_VERSION = '1.0.0';
    public const DRILL_COLUMN_ID = 'attributions';

    public function materialize(
        string $snapshotId,
        ReportScope $scope,
        array $filters,
        array $report,
        array $drillsByKey,
        DateTimeImmutable $asOf,
        ?DateTimeImmutable $staleAt,
        BudgetingReportSourceClose $close,
    ): ReportSourceSnapshotWrite {
        $queryHash = $this->hash([
            'filters' => $this->canonicalFilters($filters),
            'scope' => $scope->canonicalIdentity(),
        ]);
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
            $queryHash,
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
        );

        return new ReportSourceSnapshotWrite($header, $rows, $drillRows);
    }

    private function rows(string $snapshotId, mixed $reportRows): array
    {
        if (!is_array($reportRows) || !array_is_list($reportRows)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_rows_invalid');
        }

        $rows = [];
        foreach ($reportRows as $reportRow) {
            if (!is_array($reportRow) || !is_string($reportRow['drill_down_key'] ?? null)) {
                throw new InvalidArgumentException('project_margin_source_snapshot_rows_invalid');
            }

            $payload = $this->redactRow($reportRow);
            $rows[] = ['key' => 'margin:'.hash('sha256', CanonicalJson::encode($payload['group'])), 'payload' => $payload];
        }

        usort($rows, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        $keys = [];
        foreach ($rows as $ordinal => $row) {
            if (isset($keys[$row['key']])) {
                throw new InvalidArgumentException('project_margin_source_snapshot_rows_invalid');
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
        foreach ($rows as $row) {
            $drillKey = $row->payload['drill']['key'];
            $items = $drillsByKey[$drillKey] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new InvalidArgumentException('project_margin_source_snapshot_drill_invalid');
            }

            $payloads = array_map(fn (mixed $item): array => $this->redactDrill($item), $items);
            usort($payloads, static fn (array $left, array $right): int => $left['attribution_ref'] <=> $right['attribution_ref']);
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
        if (!is_array($group)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_rows_invalid');
        }

        return [
            'actual' => $this->moneyBlock($row['actual'] ?? null),
            'currency' => $this->string($row['currency'] ?? null),
            'drill' => ['column_id' => self::DRILL_COLUMN_ID, 'key' => $this->string($row['drill_down_key'] ?? null)],
            'forecast' => $this->moneyBlock($row['forecast'] ?? null),
            'group' => $this->scalarMap($group),
            'plan' => $this->moneyBlock($row['plan'] ?? null),
            'problem_flags' => $this->stringList($row['problem_flags'] ?? []),
            'quality_status' => $this->string($row['quality_status'] ?? null),
            'risk_flags' => $this->stringList($row['risk_flags'] ?? []),
            'source_rows_count' => $this->integer($row['source_rows_count'] ?? null),
            'source_types' => $this->stringList($row['source_types'] ?? []),
            'variance' => $this->moneyBlock($row['variance'] ?? null),
        ];
    }

    private function redactDrill(mixed $item): array
    {
        if (!is_array($item) || !is_string($item['line_id'] ?? null)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_drill_invalid');
        }

        return [
            'amount_without_vat' => $this->number($item['amount_without_vat'] ?? null),
            'attribution_ref' => hash('sha256', $item['line_id']),
            'attribution_rule' => $this->string($item['attribution_rule'] ?? null),
            'component' => $this->string($item['component'] ?? null),
            'confirmation_status' => $this->string($item['confirmation_status'] ?? null),
            'currency' => $this->string($item['currency'] ?? null),
            'direction' => $this->string($item['direction'] ?? null),
            'freshness_status' => $this->string($item['freshness_status'] ?? null),
            'management_amount' => $this->nullableNumber($item['management_amount'] ?? null),
            'period' => $this->string($item['period'] ?? null),
            'problem_flags' => $this->stringList($item['problem_flags'] ?? []),
            'quality_status' => $this->string($item['quality_status'] ?? null),
            'recognition_date' => $this->string($item['recognition_date'] ?? null),
            'recognition_event' => $this->string($item['recognition_event'] ?? null),
            'reconciliation_status' => $this->string($item['reconciliation_status'] ?? null),
            'risk_flags' => $this->stringList($item['risk_flags'] ?? []),
            'source_type' => $this->string($item['source_type'] ?? null),
            'vat_amount' => $this->nullableNumber($item['vat_amount'] ?? null),
        ];
    }

    private function canonicalFilters(array $filters): array
    {
        $allowed = ['budget_article_id', 'budget_version_uuid', 'contract_id', 'counterparty_id', 'currency', 'group_by', 'organization_id', 'period_end', 'period_start', 'project_id', 'responsibility_center_id', 'scenario_uuid'];
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
        $sourceTypes = [];
        $sourceRowsCount = 0;
        foreach ($rows as $row) {
            $sourceRowsCount += $row->payload['source_rows_count'];
            foreach ($row->payload['source_types'] as $sourceType) {
                $sourceTypes[$sourceType] = true;
            }
        }

        $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
        $period = is_array($report['period'] ?? null) ? $report['period'] : [];
        $watermarks = [
            'budget_version_uuid' => $filters['budget_version_uuid'] ?? null,
            'period_end' => $period['to'] ?? null,
            'period_start' => $period['from'] ?? null,
            'scenario_uuid' => $filters['scenario_uuid'] ?? null,
            'source_rows_count' => $sourceRowsCount,
            'source_types' => array_keys($sourceTypes),
        ];
        sort($watermarks['source_types'], SORT_STRING);

        return [...$watermarks, ...$close->snapshotWatermarks()];
    }

    private function moneyBlock(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_rows_invalid');
        }

        $result = [];
        foreach (['cost', 'gross_margin', 'margin_percent', 'revenue'] as $key) {
            if (array_key_exists($key, $value)) {
                $result[$key] = $key === 'margin_percent'
                    ? $this->nullableNumber($value[$key])
                    : $this->number($value[$key]);
            }
        }

        return $result;
    }

    private function scalarMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || (!is_scalar($item) && $item !== null)) {
                throw new InvalidArgumentException('project_margin_source_snapshot_rows_invalid');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || array_filter($value, static fn (mixed $item): bool => !is_string($item)) !== []) {
            throw new InvalidArgumentException('project_margin_source_snapshot_invalid');
        }

        $value = array_values(array_unique($value));
        sort($value, SORT_STRING);

        return $value;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('project_margin_source_snapshot_invalid');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('project_margin_source_snapshot_invalid');
        }

        return $value;
    }

    private function number(mixed $value): float|int
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('project_margin_source_snapshot_invalid');
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
