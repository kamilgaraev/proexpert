<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportConformanceFixture;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiFixtureSnapshotStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CiFixtureSourceSnapshotWriter;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportAdapter;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportBindingFactory;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReadinessProbe;
use DateTimeImmutable;
use DateTimeZone;

final class R15CiRuntimeFixtureFactory
{
    /** @return array{candidate: \App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition, binding: \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding, context: ReportExecutionContext, query: ReportQuery, fixture: ReportConformanceFixture, drill: ReportDrillDownCell} */
    public function build(): array
    {
        $candidate = (new ReportDefinitionBuilder)
            ->code(ProcurementCycleReportAdapter::REPORT_CODE)
            ->definitionHash(new Sha256Hash(str_repeat('a', 64)))
            ->contractVersion('1.0.0')
            ->formulaVersion(ProcurementCycleReportAdapter::FORMULA_VERSION)
            ->sourceSchemaVersion(ProcurementCycleReportAdapter::SCHEMA_VERSION)
            ->rendererVersion('1.0.0')
            ->filters([['id' => 'project_ids'], ['id' => 'as_of']])
            ->columns(array_map(static fn (string $id): array => ['id' => $id], [
                'row_key', 'cohort_date', 'purchase_request_line_id', 'request_number', 'material_name',
                'requester_id', 'buyer_id', 'priority', 'current_stage', 'outcome', 'total_cycle_seconds',
                'open_age_seconds', 'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status',
                'gap_codes', ProcurementCycleReportAdapter::STAGE_DRILL_COLUMN, ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN,
            ]))
            ->sorts([['id' => ProcurementCycleReportAdapter::SORT_FIELD]])
            ->formats(['csv', 'xlsx', 'pdf'])
            ->permissionPolicy(new ReportPermissionPolicy(['procurement.dashboard.view'], ['procurement.reports.export'], [], ['procurement.audit.view']))
            ->outputClassification(new ReportOutputClassification(ReportDataClassification::STANDARD, [], [], false, false, false))
            ->snapshotClassification(ReportSnapshotClassification::OPERATIONAL)
            ->sourceModule('reports')
            ->coreAccessMode(ReportCoreAccessMode::REPORTING_WORKSPACE)
            ->candidate();

        $context = (new ReportExecutionContextBuilder)->build();
        $query = new ReportQuery($candidate->payload(), $context->scope, new ReportFilterSet(['project_ids' => [1], 'as_of' => '2026-08-01']), [], new DateTimeImmutable('2026-08-01T00:00:00+00:00'), 'ru');
        $id = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
        $payload = ['cohort_date' => '2026-08-01', 'purchase_request_line_id' => 3, 'request_number' => 'PR-1', 'material_name' => 'Material', 'requester_id' => 4, 'buyer_id' => 5, 'priority' => 'normal', 'current_stage' => 'request_approval', 'outcome' => 'open', 'total_cycle_seconds' => null, 'open_age_seconds' => 1, 'awarded_supplier_party_id' => null, 'awarded_amount' => null, 'currency' => 'RUB', 'quality_status' => 'FULL', 'gap_codes' => [], ProcurementCycleReportAdapter::STAGE_DRILL_COLUMN => true, ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN => true];
        $row = new ReportSourceSnapshotRow($id, 1, 'procurement-line:3', $payload, new Sha256Hash(hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($payload))));
        $drillPayload = ['event_code' => 'request_created', 'occurred_at' => '2026-08-01T00:00:00.000000Z'];
        $drill = new ReportSourceSnapshotDrillRow($id, 'procurement-line:3', ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN, 1, $drillPayload, new Sha256Hash(hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($drillPayload))));
        $sourceHash = new Sha256Hash(str_repeat('b', 64));
        $watermarks = ['formula_version' => ProcurementCycleReportAdapter::FORMULA_VERSION, 'max_event_id' => 1, 'max_occurred_at' => '2026-08-01T00:00:00.000000Z', 'open_count' => 1, 'row_count' => 1, 'complete_count' => 0, 'cancelled_count' => 0, 'invalid_count' => 0, 'incomplete_count' => 1, 'gap_count' => 0, 'sla_eligible_count' => 0, 'sla_met_count' => 0, 'report_query_hash' => $query->queryHash->value, 'source_snapshot_query_hash' => $query->queryHash->value];
        $header = new ReportSourceSnapshotHeader($id, ProcurementCycleReportAdapter::SOURCE_KIND, ProcurementCycleReportAdapter::REPORT_CODE, ProcurementCycleReportAdapter::SCHEMA_VERSION, $context->scope, $query->queryHash, $query->asOf, $sourceHash, $watermarks, $query->asOf, null, ReportSourceSnapshotStatus::WRITING, 1, 1, new Sha256Hash(str_repeat('0', 64)), null, null, $query->identity->projection, $query->queryHash);
        $write = new ReportSourceSnapshotWrite($header, [$row], [$drill]);
        $sealed = new ReportSourceSnapshotHeader($id, $header->sourceKind, $header->reportCode, $header->schemaVersion, $header->scope, $header->queryHash, $header->asOf, $header->sourceHash, $header->watermarks, $header->generatedAt, null, ReportSourceSnapshotStatus::WRITING, 1, 1, ReportSourceSnapshotIntegrity::hash($header, [$row], [$drill]), null, null, $header->reportQueryIdentity, $header->reportQueryHash);
        $write = new ReportSourceSnapshotWrite($sealed, [$row], [$drill]);
        $adapter = new ProcurementCycleReportAdapter(new R15CiFixtureSourceSnapshotWriter($write), new R15CiFixtureSnapshotStore($write));
        $binding = (new ProcurementCycleReportBindingFactory($adapter, new ProcurementCycleReadinessProbe))->create($candidate->payload());
        $snapshot = $adapter->materialize($context, $query, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress(0));
        $result = $adapter->result($context, $snapshot);
        $drillCell = new ReportDrillDownCell('procurement-line:3', ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN);
        $drillResult = $adapter->drillDown($context, $snapshot, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput($drillCell, null, 10));
        $fixture = new ReportConformanceFixture(new Sha256Hash(str_repeat('c', 64)), 1, new ReportWindowSort(ProcurementCycleReportAdapter::SORT_FIELD, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection::ASC), 10, 10, new ReportDrillDownRequest('fixture', null, 10), new Sha256Hash(hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($result->totals))));

        return compact('candidate', 'binding', 'context', 'query', 'fixture', 'drillCell', 'drillResult');
    }
}
