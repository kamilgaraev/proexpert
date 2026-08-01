<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleLineResult;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSnapshotRequest;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSourceRead;
use DateTimeImmutable;
use InvalidArgumentException;

final class ProcurementCycleSourceSnapshotMaterializer
{
    public function identity(
        ProcurementCycleSnapshotRequest $request,
        ProcurementCycleSourceRead $source,
    ): ReportSourceSnapshotIdentity {
        return new ReportSourceSnapshotIdentity(
            ProcurementCycleReportAdapter::SOURCE_KIND,
            ProcurementCycleReportAdapter::REPORT_CODE,
            ProcurementCycleReportAdapter::SCHEMA_VERSION,
            $request->scope,
            $this->hash([
                'as_of' => $this->utc($request->asOf),
                'filters' => $request->filters,
                'scope' => $request->scope->canonicalIdentity(),
            ]),
            $source->sourceVersion(
                $request->scope->canonicalIdentity(),
                $request->filters,
                $this->utc($request->asOf),
            ),
        );
    }

    public function materialize(
        string $snapshotId,
        ProcurementCycleSnapshotRequest $request,
        ProcurementCycleSourceRead $source,
        array $results,
        array $eventsByLine,
    ): ReportSourceSnapshotWrite {
        $identity = $this->identity($request, $source);
        $rows = [];
        $drillRows = [];
        $resultByLine = [];
        foreach ($results as $result) {
            if (! $result instanceof ProcurementCycleLineResult) {
                throw new InvalidArgumentException('procurement_cycle_snapshot_result_invalid');
            }
            $payload = $this->payload($result, $request->filters);
            if ($payload === null || ! $this->matches($payload, $request->filters)) {
                continue;
            }
            $resultByLine[$result->purchaseRequestLineId] = [$result, $payload];
        }
        uasort($resultByLine, static function (array $left, array $right): int {
            $cohort = ($left[1]['cohort_date'] ?? '') <=> ($right[1]['cohort_date'] ?? '');
            if ($cohort !== 0) {
                return $cohort;
            }
            $line = $left[0]->purchaseRequestLineId <=> $right[0]->purchaseRequestLineId;

            return $line !== 0
                ? $line
                : ('procurement-line:'.$left[0]->purchaseRequestLineId)
                    <=> ('procurement-line:'.$right[0]->purchaseRequestLineId);
        });

        foreach (array_values($resultByLine) as $position => [$result, $payload]) {
            $row = new ReportSourceSnapshotRow(
                $snapshotId,
                $position + 1,
                'procurement-line:'.$result->purchaseRequestLineId,
                $payload,
                $this->hash($payload),
            );
            $rows[] = $row;
            foreach ($result->stageDrillRows() as $ordinal => $drill) {
                $drillRows[] = new ReportSourceSnapshotDrillRow(
                    $snapshotId,
                    $row->rowKey,
                    ProcurementCycleReportAdapter::STAGE_DRILL_COLUMN,
                    $ordinal + 1,
                    $drill,
                    $this->hash($drill),
                );
            }
            foreach ($eventsByLine[$result->purchaseRequestLineId] ?? [] as $ordinal => $event) {
                $drill = $this->auditPayload($event->auditPayload());
                $drillRows[] = new ReportSourceSnapshotDrillRow(
                    $snapshotId,
                    $row->rowKey,
                    ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN,
                    $ordinal + 1,
                    $drill,
                    $this->hash($drill),
                );
            }
        }

        $watermarks = $this->watermarks($rows, $source, $request->asOf);
        $sourceHash = $this->hash([
            'drills' => array_map(static fn (ReportSourceSnapshotDrillRow $row): array => $row->payload, $drillRows),
            'rows' => array_map(static fn (ReportSourceSnapshotRow $row): array => $row->payload, $rows),
            'watermarks' => $watermarks,
        ]);
        $header = new ReportSourceSnapshotHeader(
            $snapshotId,
            ProcurementCycleReportAdapter::SOURCE_KIND,
            ProcurementCycleReportAdapter::REPORT_CODE,
            ProcurementCycleReportAdapter::SCHEMA_VERSION,
            $request->scope,
            $identity->queryHash,
            $request->asOf,
            $sourceHash,
            $watermarks,
            $request->asOf,
            $request->staleAt,
            ReportSourceSnapshotStatus::WRITING,
            count($rows),
            count($drillRows),
            $this->hash(['pending' => $snapshotId]),
            null,
            null,
        );
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
            ReportSourceSnapshotIntegrity::hash($header, $rows, $drillRows),
            null,
            null,
        );

        return new ReportSourceSnapshotWrite($header, $rows, $drillRows);
    }

    private function payload(ProcurementCycleLineResult $result, array $filters): ?array
    {
        $sourcePayload = $result->row();
        $cohort = ($filters['cohort_basis'] ?? 'start') === 'outcome'
            ? ($sourcePayload['outcome_cohort_date'] ?? null)
            : ($sourcePayload['start_cohort_date'] ?? null);
        if (! is_string($cohort)) {
            return null;
        }
        $sourcePayload['cohort_date'] = $cohort;
        if (! $this->matches($sourcePayload, $filters)) {
            return null;
        }
        $payload = $this->publicRowPayload($sourcePayload);

        return [
            ...$payload,
            'cohort_date' => $cohort,
            'stage_breakdown' => true,
            'audit_timeline' => true,
        ];
    }

    private function publicRowPayload(array $payload): array
    {
        $allowed = [
            'purchase_request_line_id', 'request_number', 'material_name', 'requester_id', 'buyer_id',
            'priority', 'current_stage', 'outcome', 'total_cycle_seconds', 'open_age_seconds',
            'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status', 'gap_codes',
        ];

        $publicPayload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $publicPayload[$key] = $payload[$key];
            }
        }

        return $publicPayload;
    }

    private function auditPayload(array $payload): array
    {
        $allowed = [
            'event_id', 'event_code', 'occurred_at', 'actor_id', 'supplier_request_id',
            'supplier_request_line_id', 'supplier_party_id', 'supplier_proposal_id',
            'supplier_proposal_version_id', 'supplier_proposal_decision_id', 'purchase_order_id',
            'purchase_order_item_id', 'purchase_receipt_id', 'purchase_receipt_line_id',
        ];

        $publicPayload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $publicPayload[$key] = $payload[$key];
            }
        }

        return $publicPayload;
    }

    private function matches(array $row, array $filters): bool
    {
        $equals = [
            'requester_id', 'buyer_id', 'material_id', 'material_category_id', 'priority', 'current_stage', 'outcome',
        ];
        foreach ($equals as $key) {
            if (array_key_exists($key, $filters) && ($row[$key] ?? null) !== $filters[$key]) {
                return false;
            }
        }
        if (isset($filters['supplier_party_id'])) {
            $suppliers = $row['solicited_supplier_ids'] ?? [];
            if (($row['awarded_supplier_party_id'] ?? null) !== $filters['supplier_party_id']
                && (! is_array($suppliers) || ! in_array($filters['supplier_party_id'], $suppliers, true))) {
                return false;
            }
        }
        if (isset($filters['currency']) && ($row['currency'] ?? null) !== $filters['currency']) {
            return false;
        }
        if (isset($filters['award_amount_min'])
            && (($row['awarded_amount'] ?? null) === null
                || $this->minorAmount((string) $row['awarded_amount']) < $this->minorAmount($filters['award_amount_min']))) {
            return false;
        }
        if (isset($filters['award_amount_max'])
            && (($row['awarded_amount'] ?? null) === null
                || $this->minorAmount((string) $row['awarded_amount']) > $this->minorAmount($filters['award_amount_max']))) {
            return false;
        }
        if (isset($filters['period_start']) && $row['cohort_date'] < $filters['period_start']) {
            return false;
        }
        if (isset($filters['period_end']) && $row['cohort_date'] > $filters['period_end']) {
            return false;
        }

        return true;
    }

    private function watermarks(array $rows, ProcurementCycleSourceRead $source, DateTimeImmutable $asOf): array
    {
        $counts = [
            'cancelled_count' => 0,
            'complete_count' => 0,
            'gap_count' => 0,
            'incomplete_count' => 0,
            'invalid_count' => 0,
            'open_count' => 0,
            'sla_eligible_count' => 0,
            'sla_met_count' => 0,
        ];
        foreach ($rows as $row) {
            $outcome = $row->payload['outcome'] ?? null;
            if ($outcome === 'completed') {
                $counts['complete_count']++;
            } elseif ($outcome === 'cancelled') {
                $counts['cancelled_count']++;
            } elseif ($outcome === 'invalid_source') {
                $counts['invalid_count']++;
            } elseif ($outcome === 'incomplete') {
                $counts['incomplete_count']++;
            } else {
                $counts['open_count']++;
            }
            if (($row->payload['gap_codes'] ?? []) !== []) {
                $counts['gap_count']++;
            }
            foreach ($row->payload['stage_metrics'] ?? [] as $metric) {
                $counts['sla_eligible_count'] += (int) ($metric['denominator'] ?? 0);
                $counts['sla_met_count'] += (int) ($metric['numerator'] ?? 0);
            }
            $counts['sla_eligible_count'] += (int) ($row->payload['total_sla_denominator'] ?? 0);
            $counts['sla_met_count'] += (int) ($row->payload['total_sla_numerator'] ?? 0);
        }

        return [
            ...$counts,
            'as_of' => $this->utc($asOf),
            'event_schema_version' => 'procurement-process-events.v1',
            'formula_version' => ProcurementCycleReportAdapter::FORMULA_VERSION,
            'max_event_id' => $source->maxEventId,
            'max_occurred_at' => $source->maxOccurredAt,
            'source_event_count' => $source->eventCount,
            'source_line_count' => $source->lineCount,
            'unscoped_quarantine_line_count' => $source->unscopedQuarantineLineCount,
            'unscoped_quarantine_max_event_id' => $source->unscopedQuarantineMaxEventId,
            'unscoped_quarantine_max_occurred_at' => $source->unscopedQuarantineMaxOccurredAt,
            'source_schema_version' => ProcurementCycleReportAdapter::SCHEMA_VERSION,
        ];
    }

    private function hash(array $value): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($value)));
    }

    private function minorAmount(string $value): int
    {
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('procurement_cycle_snapshot_amount_invalid');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
