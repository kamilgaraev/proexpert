<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportWireDtoContractTest extends TestCase
{
    #[Test]
    public function snapshot_keeps_its_identity(): void
    {
        $snapshot = $this->snapshot();

        self::assertSame('sales_snapshot', $snapshot->kind);
        self::assertSame('snapshot_1', $snapshot->id);
        self::assertSame('formula_v1', $snapshot->formulaVersion);
        self::assertSame('UTC', $snapshot->scope->timezone->getName());
        self::assertSame($this->hash()->value, $snapshot->definitionHash->value);
        self::assertSame($this->hash('b')->value, $snapshot->sourceHash->value);

        try {
            new ReportSnapshotRef('sales_snapshot', 'snapshot_1', $this->scope(), $this->hash(), 'formula_v1', $this->hash('b'), $this->at('+1 minute'), $this->at(), []);
            self::fail('Допущен устаревший снимок.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('snapshot_identity_invalid', $exception->getMessage());
        }
    }

    #[Test]
    public function source_ref_accepts_safe_reference(): void
    {
        $source = $this->source();

        self::assertSame('erp', $source->source);
        self::assertSame(3, $source->rowCount);
        self::assertSame('sales_snapshot', $source->snapshotKind);
        self::assertSame('schema_v1', $source->schemaVersion);
        self::assertSame('watermark_v1', $source->watermark);
        self::assertSame($this->hash('b')->value, $source->hash->value);
        self::assertSame('snapshot_1', $source->snapshotId);
    }

    #[Test]
    public function source_ref_rejects_unsafe_reference(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_source_ref_invalid'));
        new ReportSourceRef('https://private.example', 'sales_snapshot', 'snapshot_1', 'schema_v1', 'watermark_v1', 0, $this->hash());
    }

    #[Test]
    public function coverage_accepts_nonzero_decimal_ratio(): void
    {
        $coverage = new ReportCoverage('3', '4', '0.75');

        self::assertSame('0.75', $coverage->ratio);
        self::assertSame('3', $coverage->numerator);
        self::assertSame('4', $coverage->denominator);
        self::assertInstanceOf(ReportCoverage::class, $coverage);
    }

    #[Test]
    public function coverage_requires_null_ratio_for_zero_denominator(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_coverage_invalid'));
        new ReportCoverage('0', '0', '0');
    }

    #[Test]
    public function warning_requires_stable_code_and_safe_metric(): void
    {
        $warning = new ReportWarning('MISSING_COST', ReportWarningSeverity::WARNING, 'cost_total', 2);

        self::assertSame('MISSING_COST', $warning->code);
        $this->expectException(InvalidArgumentException::class);
        new ReportWarning('missing_cost', ReportWarningSeverity::WARNING, 'raw sql', 0);
    }

    #[Test]
    public function quality_rejects_complete_state_with_critical_warning(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_quality_invalid'));
        new ReportQuality(ReportQualityStatus::COMPLETE, null, [new ReportWarning('MISSING_COST', ReportWarningSeverity::CRITICAL, null, 1)], 0, ReportReconciliationStatus::MATCHED, [], []);
    }

    #[Test]
    public function quality_rejects_unknown_metric_for_complete_state(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_quality_invalid'));
        new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, ['cost_total'], []);
    }

    #[Test]
    public function provenance_keeps_typed_sources(): void
    {
        $provenance = $this->provenance();

        self::assertCount(1, $provenance->sourceRefs);
        self::assertSame('erp', $provenance->sourceOfTruth);
        self::assertSame($this->hash('b')->value, $provenance->sourceHash->value);
        self::assertSame('auditor', $provenance->externalConfirmationRole);
        self::assertInstanceOf(ReportSourceRef::class, $provenance->sourceRefs[0]);
    }

    #[Test]
    public function provenance_rejects_sensitive_values_at_any_depth(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_provenance_invalid'));
        new ReportProvenance('erp', [$this->source()], $this->hash(), 'email');
    }

    #[Test]
    public function result_metadata_requires_exact_snapshot_timestamps(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_result_metadata_invalid'));
        new ReportResultMetadata($this->snapshot(), 1, $this->at('+1 second'), null);
    }

    #[Test]
    public function result_requires_matching_provenance_hash(): void
    {
        $metadata = $this->metadata();
        $this->expectExceptionObject(new InvalidArgumentException('report_result_invalid'));
        new ReportResult($metadata, [], ReportFreshnessStatus::FRESH, $this->quality(), new ReportProvenance('erp', [$this->source()], $this->hash('c'), null), [['id' => 'amount']], []);
    }

    #[Test]
    public function result_rejects_duplicate_schema_ids_and_dynamic_unsupported_values(): void
    {
        $metadata = $this->metadata();
        $this->expectException(InvalidArgumentException::class);
        new ReportResult($metadata, ['amount' => fopen('php://memory', 'rb')], ReportFreshnessStatus::FRESH, $this->quality(), $this->provenance(), [['id' => 'amount'], ['id' => 'amount']], []);
    }

    #[Test]
    public function cursor_requires_uppercase_ulid_and_future_expiry(): void
    {
        $cursor = new ReportCursor('signed.cursor', $this->ulid(), $this->hash(), $this->hash('b'), $this->sort(), new DateTimeImmutable('+1 hour'));

        self::assertSame($this->ulid(), $cursor->runId);
        $this->expectException(InvalidArgumentException::class);
        new ReportCursor('', strtolower($this->ulid()), $this->hash(), $this->hash('b'), $this->sort(), new DateTimeImmutable('-1 second'));
    }

    #[Test]
    public function drill_down_request_limits_window(): void
    {
        $request = new ReportDrillDownRequest('signed.request', null, 100);

        self::assertSame(100, $request->limit);
        $this->expectException(InvalidArgumentException::class);
        new ReportDrillDownRequest('signed.request', null, 101);
    }

    #[Test]
    public function page_requires_associative_unique_row_keys(): void
    {
        foreach ([[['row_key' => 'one'], ['row_key' => 'one']], [['row_key' => 1]]] as $rows) {
            try {
                new ReportPage($rows, [], ReportFreshnessStatus::FRESH, $this->quality(), null, 10, false, $this->sort());
                self::fail('Допущены недопустимые ключи строк.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_page_invalid', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function resource_link_rejects_internal_route_targets(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('report_resource_link_invalid'));
        new ReportResourceLink('project', 'project_1', 'admin.reports.show', ['url' => 'https://example.test'], 'available');
    }

    #[Test]
    public function drill_down_result_requires_typed_links_and_unique_rows(): void
    {
        $link = new ReportResourceLink('project', 'project_1', 'admin.projects.show', ['id' => 1], 'available');
        $result = new ReportDrillDownResult([['row_key' => 'one']], 'next.cursor', [$link]);

        self::assertSame('next.cursor', $result->nextCursor);
        self::assertCount(1, $result->resourceLinks);
        self::assertSame('project', $result->resourceLinks[0]->resourceType);
        self::assertSame('admin.projects.show', $result->resourceLinks[0]->routeName);
        self::assertSame(['id' => 1], $result->resourceLinks[0]->params);
    }

    #[Test]
    public function ready_run_has_created_status_and_location_headers(): void
    {
        $run = $this->reportRun(ReportRunStatus::READY, 'created');

        self::assertSame(201, $run->httpStatus);
        self::assertSame(['Location' => '/api/v1/admin/reports/runs/'.$this->ulid()], $run->responseHeaders());
        self::assertSame($this->ulid(), $run->id);
        self::assertSame(ReportRunStatus::READY, $run->status);
        self::assertSame(100, $run->progress);
        self::assertSame(3, $run->rowCount);
        self::assertSame(['amount' => 3], $run->totals);
        self::assertSame($this->hash('b')->value, $run->sourceHash?->value);
    }

    #[Test]
    public function reused_ready_run_returns_ok_without_creation_headers(): void
    {
        $run = $this->reportRun(ReportRunStatus::READY, 'reused');

        self::assertSame(200, $run->httpStatus);
        self::assertSame([], $run->responseHeaders());
        self::assertSame('reused', $run->httpDisposition);
        self::assertNull($run->pollAfterMs);
    }

    #[Test]
    public function async_run_returns_retry_after_ceil_seconds(): void
    {
        $run = $this->reportRun(ReportRunStatus::QUEUED, 'created', 1001);

        self::assertSame(202, $run->httpStatus);
        self::assertSame(['Retry-After' => 2], $run->responseHeaders());
        self::assertSame(1001, $run->pollAfterMs);
    }

    #[Test]
    public function run_enforces_ready_and_non_ready_identity_tuples(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reportRun(ReportRunStatus::QUEUED, 'created', 1, 0);
    }

    #[Test]
    public function ready_export_has_relative_location_and_complete_artifact(): void
    {
        $export = $this->export(ReportExportStatus::READY, 'created');

        self::assertSame(201, $export->httpStatus);
        self::assertSame(['Location' => '/api/v1/admin/reports/exports/'.$this->ulid()], $export->responseHeaders());
        self::assertSame('csv', $export->format);
        self::assertSame(['row_key', 'amount'], $export->columns);
        self::assertSame('org-1/reports/export.csv', $export->artifactPath);
        self::assertSame('version_1', $export->versionId);
        self::assertSame(10, $export->sizeBytes);
        self::assertSame(3, $export->rowCount);
    }

    #[Test]
    public function export_enforces_ready_artifact_tuple_and_safe_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->export(ReportExportStatus::READY, 'created', null, 'https://bucket.test/report.csv');
    }

    #[Test]
    public function export_uses_retry_after_only_while_async(): void
    {
        $export = $this->export(ReportExportStatus::UPLOADING, 'reused', 1999);

        self::assertSame(202, $export->httpStatus);
        self::assertSame(['Retry-After' => 2], $export->responseHeaders());
        self::assertSame(1999, $export->pollAfterMs);
    }

    #[Test]
    public function download_link_requires_https_and_short_lifetime(): void
    {
        $link = new ReportDownloadLink('https://storage.example/report.csv', 'version_1', $this->at(), $this->at('+300 seconds'));

        self::assertSame('version_1', $link->versionId);
        self::assertSame('https://storage.example/report.csv', $link->url);
        self::assertSame(300, $link->expiresAt->getTimestamp() - $link->issuedAt->getTimestamp());
        $this->expectException(InvalidArgumentException::class);
        new ReportDownloadLink('http://storage.example/report.csv', 'version_1', $this->at(), $this->at('+301 seconds'));
    }

    private function snapshot(): ReportSnapshotRef
    {
        return new ReportSnapshotRef('sales_snapshot', 'snapshot_1', $this->scope(), $this->hash(), 'formula_v1', $this->hash('b'), $this->at(), null, ['erp' => 'watermark_1']);
    }

    private function source(): ReportSourceRef
    {
        return new ReportSourceRef('erp', 'sales_snapshot', 'snapshot_1', 'schema_v1', 'watermark_v1', 3, $this->hash('b'));
    }

    private function metadata(): ReportResultMetadata
    {
        return new ReportResultMetadata($this->snapshot(), 3, $this->at(), null);
    }

    private function provenance(): ReportProvenance
    {
        return new ReportProvenance('erp', [$this->source()], $this->hash('b'), 'auditor');
    }

    private function quality(): ReportQuality
    {
        return new ReportQuality(ReportQualityStatus::COMPLETE, new ReportCoverage('3', '3', '1'), [], 0, ReportReconciliationStatus::MATCHED, [], []);
    }

    private function reportRun(ReportRunStatus $status, string $disposition, ?int $pollAfterMs = null, ?int $rowCount = null): ReportRun
    {
        $ready = $status === ReportRunStatus::READY;
        $metadata = $ready ? $this->metadata() : null;

        return new ReportRun($this->ulid(), 'sales_overview', $status, $this->hash(), 'contract_v1', 'formula_v1', 'schema_v1', 'renderer_v1', $this->hash('c'), $ready ? $this->hash('b') : null, $ready ? 100 : 0, $rowCount ?? ($ready ? 3 : null), $metadata, $ready ? ['amount' => 3] : [], $ready ? ReportFreshnessStatus::FRESH : null, $ready ? $this->quality() : null, $ready ? $this->provenance() : null, $this->at('-1 minute'), $this->at(), $ready ? $this->at() : null, $this->at('+1 hour'), null, $disposition, $pollAfterMs);
    }

    private function export(ReportExportStatus $status, string $disposition, ?int $pollAfterMs = null, ?string $artifactPath = 'org-1/reports/export.csv'): ReportExport
    {
        $ready = $status === ReportExportStatus::READY;

        return new ReportExport($this->ulid(), $this->ulid(), $status, $this->hash(), 'csv', ['row_key', 'amount'], $this->sort(), 'ru', new DateTimeZone('UTC'), $ready ? $artifactPath : null, $ready ? 'version_1' : null, $ready ? 'etag_1' : null, $ready ? $this->hash('b') : null, $ready ? 10 : null, $ready ? 3 : null, $this->at('-1 minute'), $this->at(), $ready ? $this->at() : null, $this->at('+1 hour'), null, $disposition, $pollAfterMs);
    }

    private function scope(): ReportScope
    {
        return new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
    }

    private function sort(): ReportWindowSort
    {
        return new ReportWindowSort('row_key', ReportSortDirection::ASC);
    }

    private function hash(string $character = 'a'): Sha256Hash
    {
        return new Sha256Hash(str_repeat($character, 64));
    }

    private function ulid(): string
    {
        return '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    }

    private function at(string $modifier = 'now'): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-26T12:00:00+00:00 '.$modifier);
    }
}
