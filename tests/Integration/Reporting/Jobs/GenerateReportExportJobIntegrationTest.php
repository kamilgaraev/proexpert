<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Jobs;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactVersionInventory;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionService;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Storage\DTO\MultipartPart;
use App\Services\Storage\DTO\MultipartUpload;
use App\Services\Storage\DTO\StoredFile;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class GenerateReportExportJobIntegrationTest extends TestCase
{
    private const EXPORT_ID = '01J00000000000000000000001';

    private const RUN_ID = '01J00000000000000000000000';

    private const TOKEN = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';

    public function test_duplicate_delivery_stops_at_authority_free_claim(): void
    {
        $attempts = $this->createMock(ReportExportAttemptLifecycleStore::class);
        $attempts->expects(self::once())
            ->method('claimOrRenew')
            ->willReturn(false);
        $contexts = $this->createMock(ReportExportExecutionContextRehydrator::class);
        $contexts->expects(self::never())->method('forExport');
        $exports = $this->createMock(ReportExportStore::class);
        $exports->expects(self::never())->method('get');

        $this->minimalService($attempts, $contexts, $exports)->execute(
            self::EXPORT_ID,
            self::TOKEN,
        );
    }

    public function test_retryable_bootstrap_failure_keeps_lease_and_rethrows_safe_code(): void
    {
        $attempts = $this->createMock(ReportExportAttemptLifecycleStore::class);
        $attempts->expects(self::once())->method('claimOrRenew')->willReturn(true);
        $attempts->expects(self::never())->method('failLeased');
        $contexts = $this->createMock(ReportExportExecutionContextRehydrator::class);
        $contexts->expects(self::once())->method('forExport')->willThrowException(
            ReportContractException::fromCode(
                ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
            ),
        );
        $telemetry = $this->createMock(ReportExecutionTelemetry::class);
        $telemetry->expects(self::once())
            ->method('executionAttempt')
            ->with('export', ReportErrorCode::REPORT_SOURCE_UNAVAILABLE->value);

        try {
            $this->minimalService(
                $attempts,
                $contexts,
                $this->createMock(ReportExportStore::class),
                $telemetry,
            )->execute(self::EXPORT_ID, self::TOKEN);
            self::fail('Retryable bootstrap failure must escape.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
        }
    }

    public function test_full_execution_reauthorizes_every_boundary_and_pins_one_version(): void
    {
        $fixture = $this->fixture();
        $files = new RecordingExportFileService;
        $inventory = new LinkedExportInventory($files);
        $store = new RecordingExecutionExportStore(
            $fixture['export'],
            ReportExportStatus::RUNNING,
        );
        $attempts = new RecordingExportAttemptStore($store);
        $authorizer = new CountingExportAuthorizer($fixture['context']);
        $telemetry = new RecordingExportTelemetry;

        $this->fullService(
            $fixture,
            $attempts,
            $store,
            $files,
            $inventory,
            $authorizer,
            $telemetry,
        )->execute(self::EXPORT_ID, self::TOKEN);

        self::assertSame(ReportExportStatus::READY, $store->status);
        self::assertSame(1, $files->startCount);
        self::assertSame(1, $files->completeCount);
        self::assertSame(0, $files->abortCount);
        self::assertSame(1, $store->startRenderingCount);
        self::assertSame(1, $store->startUploadingCount);
        self::assertSame(1, $store->sealCount);
        self::assertSame(5, $authorizer->calls);
        self::assertSame(1, $telemetry->artifacts);
    }

    public function test_retry_from_uploading_seals_exact_completed_version_without_new_multipart(): void
    {
        $fixture = $this->fixture(ReportExportStatus::UPLOADING);
        $files = new RecordingExportFileService;
        $files->versions[] = $this->version($fixture);
        $store = new RecordingExecutionExportStore(
            $fixture['export'],
            ReportExportStatus::UPLOADING,
        );
        $attempts = new RecordingExportAttemptStore($store);
        $authorizer = new CountingExportAuthorizer($fixture['context']);

        $this->fullService(
            $fixture,
            $attempts,
            $store,
            $files,
            new LinkedExportInventory($files),
            $authorizer,
            new RecordingExportTelemetry,
        )->execute(self::EXPORT_ID, self::TOKEN);

        self::assertSame(ReportExportStatus::READY, $store->status);
        self::assertSame(0, $files->startCount);
        self::assertSame(1, $store->sealCount);
        self::assertSame('version-1', $store->sealedArtifact?->versionId);
        self::assertSame(2, $authorizer->calls);
    }

    public function test_post_completion_store_failure_is_recovered_without_second_artifact(): void
    {
        $fixture = $this->fixture();
        $files = new RecordingExportFileService;
        $store = new RecordingExecutionExportStore(
            $fixture['export'],
            ReportExportStatus::RUNNING,
        );
        $store->sealFailures = 1;
        $attempts = new RecordingExportAttemptStore($store);
        $inventory = new LinkedExportInventory($files);

        try {
            $this->fullService(
                $fixture,
                $attempts,
                $store,
                $files,
                $inventory,
                new CountingExportAuthorizer($fixture['context']),
                new RecordingExportTelemetry,
            )->execute(self::EXPORT_ID, self::TOKEN);
            self::fail('Post-completion store failure must be retried.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
        self::assertSame(ReportExportStatus::UPLOADING, $store->status);
        self::assertSame(1, $files->completeCount);
        self::assertCount(1, $files->versions);

        $this->fullService(
            $fixture,
            $attempts,
            $store,
            $files,
            $inventory,
            new CountingExportAuthorizer($fixture['context']),
            new RecordingExportTelemetry,
        )->execute(self::EXPORT_ID, self::TOKEN);

        self::assertSame(ReportExportStatus::READY, $store->status);
        self::assertSame(1, $files->startCount);
        self::assertSame(1, $files->completeCount);
        self::assertCount(1, $files->versions);
        self::assertSame(2, $store->sealCount);
    }

    public function test_retry_from_uploading_without_completed_version_restarts_aborted_rendering(): void
    {
        $fixture = $this->fixture(ReportExportStatus::UPLOADING);
        $files = new RecordingExportFileService;
        $store = new RecordingExecutionExportStore(
            $fixture['export'],
            ReportExportStatus::UPLOADING,
        );

        $this->fullService(
            $fixture,
            new RecordingExportAttemptStore($store),
            $store,
            $files,
            new LinkedExportInventory($files),
            new CountingExportAuthorizer($fixture['context']),
            new RecordingExportTelemetry,
        )->execute(self::EXPORT_ID, self::TOKEN);

        self::assertSame(ReportExportStatus::READY, $store->status);
        self::assertSame(0, $store->startRenderingCount);
        self::assertSame(0, $store->startUploadingCount);
        self::assertSame(1, $files->completeCount);
    }

    public function test_cancellation_between_chunks_aborts_once_and_never_seals_ready(): void
    {
        $fixture = $this->fixture();
        $files = new RecordingExportFileService;
        $store = new RecordingExecutionExportStore(
            $fixture['export'],
            ReportExportStatus::RUNNING,
        );
        $store->cancelDuringRender = true;
        $attempts = new RecordingExportAttemptStore($store);

        $this->fullService(
            $fixture,
            $attempts,
            $store,
            $files,
            new LinkedExportInventory($files),
            new CountingExportAuthorizer($fixture['context']),
            new RecordingExportTelemetry,
        )->execute(self::EXPORT_ID, self::TOKEN);

        self::assertSame(1, $files->abortCount);
        self::assertSame(0, $files->completeCount);
        self::assertSame(0, $store->sealCount);
        self::assertSame(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED, $attempts->failedCode);
    }

    private function minimalService(
        ReportExportAttemptLifecycleStore $attempts,
        ReportExportExecutionContextRehydrator $contexts,
        ReportExportStore $exports,
        ?ReportExecutionTelemetry $telemetry = null,
    ): ReportExportExecutionService {
        return new ReportExportExecutionService(
            $attempts,
            $contexts,
            $exports,
            $this->createMock(ReportRunStore::class),
            $this->createMock(ReportDefinitionRegistry::class),
            $this->createMock(ReportDefinitionBindingAssembler::class),
            new ReportRowChunkReader,
            (new ReflectionClass(ReportExportRendererRegistry::class))
                ->newInstanceWithoutConstructor(),
            $this->createMock(FileService::class),
            $this->createMock(ReportArtifactVersionInventory::class),
            new FakeReportExecutionClock(new DateTimeImmutable('2026-01-01T00:02:00Z')),
            $telemetry ?? $this->createMock(ReportExecutionTelemetry::class),
            $this->createMock(ReportAuthorizationSubjectReader::class),
            $this->createMock(CurrentReportExactManyAuthorizer::class),
            new ReportExecutionContextFactory,
        );
    }

    private function fullService(
        array $fixture,
        ReportExportAttemptLifecycleStore $attempts,
        ReportExportStore $exports,
        FileService $files,
        ReportArtifactVersionInventory $inventory,
        CurrentReportExactManyAuthorizer $authorizer,
        ReportExecutionTelemetry $telemetry,
    ): ReportExportExecutionService {
        $contexts = $this->createMock(ReportExportExecutionContextRehydrator::class);
        $contexts->method('forExport')->willReturn($fixture['context']);
        $runs = $this->createMock(ReportRunStore::class);
        $runs->method('exportSource')->willReturn($fixture['source']);
        $definitions = $this->createMock(ReportDefinitionRegistry::class);
        $definitions->method('published')->willReturn($fixture['published']);
        $bindings = $this->createMock(ReportDefinitionBindingAssembler::class);
        $bindings->method('assemble')->willReturn(new ReportDefinitionBindingMap([
            'report' => $fixture['binding'],
        ]));
        $subjects = $this->createMock(ReportAuthorizationSubjectReader::class);
        $subjects->method('export')->willReturn($fixture['subject']);
        $xlsx = (new ReflectionClass(XlsxReportExportRenderer::class))
            ->newInstanceWithoutConstructor();
        $pdf = (new ReflectionClass(PdfReportExportRenderer::class))
            ->newInstanceWithoutConstructor();

        return new ReportExportExecutionService(
            $attempts,
            $contexts,
            $exports,
            $runs,
            $definitions,
            $bindings,
            new ReportRowChunkReader,
            new ReportExportRendererRegistry(
                new CsvReportExportRenderer,
                $xlsx,
                $pdf,
            ),
            $files,
            $inventory,
            new FakeReportExecutionClock(new DateTimeImmutable('2026-01-01T00:02:00Z')),
            $telemetry,
            $subjects,
            $authorizer,
            new ReportExecutionContextFactory,
        );
    }

    private function fixture(
        ReportExportStatus $status = ReportExportStatus::RUNNING,
    ): array {
        $scope = new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
        $context = (new ReportExecutionContextBuilder)->build();
        $published = (new ReportDefinitionBuilder)->published();
        $source = $this->source($scope, $published);
        $export = (new ReportExportBuilder)
            ->id(self::EXPORT_ID)
            ->runId(self::RUN_ID)
            ->status($status)
            ->exportHash(new Sha256Hash(str_repeat('d', 64)))
            ->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->updatedAt(new DateTimeImmutable('2026-01-01T00:01:00Z'))
            ->expiresAt(new DateTimeImmutable('2026-01-01T01:00:00Z'))
            ->queued();
        $rowQuery = new OneRowExportQuery($source);
        $binding = new ReportDefinitionBinding(
            'report',
            $published->definitionHash,
            $published->definition->contractVersion,
            $this->createMock(ReportDataProvider::class),
            $rowQuery,
            $this->createMock(ReportDrillDownProvider::class),
            null,
        );
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $published->definition,
            $scope,
            $source->snapshot,
            self::RUN_ID,
            null,
        );

        return compact(
            'scope',
            'context',
            'published',
            'source',
            'export',
            'binding',
            'subject',
        );
    }

    private function source(
        ReportScope $scope,
        PublishedReportDefinition $published,
    ): ReportRunExportSource {
        $definition = $published->definition;
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2025-12-31T23:58:00Z'),
            'ru',
        );
        $sourceHash = new Sha256Hash(str_repeat('c', 64));
        $generatedAt = new DateTimeImmutable('2025-12-31T23:59:00Z');
        $snapshot = new ReportSnapshotRef(
            'materialized',
            'snapshot-1',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            $sourceHash,
            $generatedAt,
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
        $provenance = new ReportProvenance(
            'system',
            [new ReportSourceRef('system', 'table', 'snapshot_1', 'v1', 'wm_1', 1, $sourceHash)],
            $sourceHash,
            null,
        );
        $metadata = new ReportResultMetadata($snapshot, 1, $generatedAt, null);
        $result = new ReportResult(
            $metadata,
            [],
            ReportFreshnessStatus::FRESH,
            $quality,
            $provenance,
            [['id' => 'name', 'label' => 'Наименование']],
            [],
        );
        $run = (new ReportRunBuilder)
            ->id(self::RUN_ID)
            ->definitionHash($definition->definitionHash)
            ->contractVersion($definition->contractVersion)
            ->formulaVersion($definition->formulaVersion)
            ->sourceSchemaVersion($definition->sourceSchemaVersion)
            ->rendererVersion($definition->rendererVersion)
            ->queryHash($query->queryHash)
            ->sourceHash($sourceHash)
            ->rowCount(1)
            ->resultMetadata($metadata)
            ->freshness(ReportFreshnessStatus::FRESH)
            ->quality($quality)
            ->provenance($provenance)
            ->createdAt(new DateTimeImmutable('2025-12-31T23:58:00Z'))
            ->updatedAt($generatedAt)
            ->readyAt($generatedAt)
            ->expiresAt(new DateTimeImmutable('2026-01-01T01:00:00Z'))
            ->ready();
        $projection = (new ReflectionClass(ReportRunExportSource::class))
            ->getMethod('resultProjection')
            ->invoke(null, $result);

        return new ReportRunExportSource(
            $run,
            $query,
            $result,
            new Sha256Hash(hash('sha256', CanonicalJson::encode($projection))),
            $snapshot,
            ReportDataClassification::STANDARD,
            new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                [],
                [],
                false,
                false,
                false,
            ),
            $definition->contractVersion,
            $definition->formulaVersion,
            $definition->sourceSchemaVersion,
            $definition->rendererVersion,
        );
    }

    private function version(array $fixture): array
    {
        $source = $fixture['source'];
        $export = $fixture['export'];

        return [
            'path' => 'org-1/reports/exports/'.self::EXPORT_ID.'/artifact.csv',
            'version_id' => 'version-1',
            'etag' => 'etag-1',
            'size' => 12,
            'sha256' => str_repeat('e', 64),
            'mime' => CsvReportExportRenderer::MIME_TYPE,
            'metadata' => [
                'organization_id' => '1',
                'export_id' => self::EXPORT_ID,
                'export_hash' => $export->exportHash->value,
                'run_id' => self::RUN_ID,
                'result_hash' => $source->resultHash->value,
                'snapshot_id' => $source->snapshot->id,
                'snapshot_classification' => $source->snapshot->classification->value,
                'data_classification' => $source->dataClassification->value,
                'contract_version' => $source->contractVersion,
                'formula_version' => $source->formulaVersion,
                'source_schema_version' => $source->sourceSchemaVersion,
                'renderer_version' => $source->rendererVersion,
            ],
            'created_at' => new DateTimeImmutable('2026-01-01T00:01:00Z'),
        ];
    }
}

final class RecordingExecutionExportStore implements ReportExportStore
{
    public int $startRenderingCount = 0;

    public int $startUploadingCount = 0;

    public int $sealCount = 0;

    public int $sealFailures = 0;

    public bool $cancelDuringRender = false;

    public ?StoredFile $sealedArtifact = null;

    public function __construct(
        private readonly ReportExport $template,
        public ReportExportStatus $status,
    ) {}

    public function createOrReuse(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        CreateReportExportData $data,
        IdempotencyKey $idempotencyKey,
        ReportAuthorizationFence $fence,
    ): ReportExport {
        throw new RuntimeException('unused');
    }

    public function get(
        ReportExecutionContext $context,
        string $exportId,
    ): ReportExport {
        if (
            $this->cancelDuringRender
            && $this->startRenderingCount > 0
            && $this->startUploadingCount === 0
        ) {
            $this->status = ReportExportStatus::CANCELLED;
        }

        return $this->current();
    }

    public function startRendering(
        ReportExecutionContext $context,
        string $exportId,
        string $leaseToken,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): ReportExport {
        ++$this->startRenderingCount;
        $this->status = ReportExportStatus::RUNNING;

        return $this->current();
    }

    public function startUploading(
        ReportExecutionContext $context,
        string $exportId,
        string $leaseToken,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): ReportExport {
        ++$this->startUploadingCount;
        $this->status = ReportExportStatus::UPLOADING;

        return $this->current();
    }

    public function sealReady(
        ReportExecutionContext $context,
        string $exportId,
        string $leaseToken,
        StoredFile $artifact,
        int $rowCount,
        DateTimeImmutable $occurredAt,
    ): ReportExport {
        ++$this->sealCount;
        $this->sealedArtifact = $artifact;
        if ($this->sealFailures > 0) {
            --$this->sealFailures;
            throw new RuntimeException('audit unavailable');
        }
        $this->status = ReportExportStatus::READY;

        return (new ReportExportBuilder)
            ->id($this->template->id)
            ->runId($this->template->runId)
            ->exportHash($this->template->exportHash)
            ->createdAt($this->template->createdAt)
            ->updatedAt($occurredAt)
            ->readyAt($occurredAt)
            ->expiresAt($this->template->expiresAt)
            ->artifactPath($artifact->path)
            ->versionId($artifact->versionId)
            ->etag($artifact->etag)
            ->checksum($artifact->checksum)
            ->sizeBytes($artifact->sizeBytes)
            ->rowCount($rowCount)
            ->ready();
    }

    public function fail(
        ReportExecutionContext $context,
        string $exportId,
        ?string $leaseToken,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
    ): ReportExport {
        $this->status = ReportExportStatus::FAILED;

        return $this->current();
    }

    public function cancel(
        ReportExecutionContext $context,
        string $exportId,
        DateTimeImmutable $occurredAt,
        ReportAuthorizationFence $fence,
    ): ReportExport {
        $this->status = ReportExportStatus::CANCELLED;

        return $this->current();
    }

    private function current(): ReportExport
    {
        if ($this->status === ReportExportStatus::READY && $this->sealedArtifact !== null) {
            return (new ReportExportBuilder)
                ->id($this->template->id)
                ->runId($this->template->runId)
                ->exportHash($this->template->exportHash)
                ->createdAt($this->template->createdAt)
                ->updatedAt(new DateTimeImmutable('2026-01-01T00:02:00Z'))
                ->readyAt(new DateTimeImmutable('2026-01-01T00:02:00Z'))
                ->expiresAt($this->template->expiresAt)
                ->artifactPath($this->sealedArtifact->path)
                ->versionId($this->sealedArtifact->versionId)
                ->etag($this->sealedArtifact->etag)
                ->checksum($this->sealedArtifact->checksum)
                ->sizeBytes($this->sealedArtifact->sizeBytes)
                ->rowCount(1)
                ->ready();
        }

        return (new ReportExportBuilder)
            ->id($this->template->id)
            ->runId($this->template->runId)
            ->status($this->status)
            ->exportHash($this->template->exportHash)
            ->createdAt($this->template->createdAt)
            ->updatedAt($this->template->updatedAt)
            ->expiresAt($this->template->expiresAt)
            ->queued();
    }
}

final class RecordingExportAttemptStore implements ReportExportAttemptLifecycleStore
{
    public ?ReportErrorCode $failedCode = null;

    public int $claims = 0;

    public function __construct(private readonly RecordingExecutionExportStore $exports) {}

    public function claimOrRenew(
        string $exportId,
        string $envelopeUuid,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): bool {
        ++$this->claims;

        return in_array(
            $this->exports->status,
            [ReportExportStatus::RUNNING, ReportExportStatus::UPLOADING],
            true,
        );
    }

    public function failLeased(
        string $exportId,
        string $envelopeUuid,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
    ): bool {
        $this->failedCode = $errorCode;

        return true;
    }
}

final class RecordingExportFileService extends FileService
{
    public int $startCount = 0;

    public int $completeCount = 0;

    public int $abortCount = 0;

    public array $versions = [];

    private array $parts = [];

    public function __construct() {}

    public function startMultipart(
        string $organizationPath,
        string $mime,
        int $partSizeBytes,
        array $metadata,
    ): MultipartUpload {
        ++$this->startCount;
        $this->parts = [];

        return new MultipartUpload(
            $organizationPath,
            'upload-1',
            $mime,
            $partSizeBytes,
            $metadata,
        );
    }

    public function uploadPart(
        MultipartUpload $upload,
        int $partNumber,
        string $bytes,
        string $checksumSha256,
    ): MultipartPart {
        $this->parts[$partNumber] = $bytes;

        return new MultipartPart(
            $upload->organizationPath,
            $upload->uploadId,
            $partNumber,
            "etag-{$partNumber}",
            strlen($bytes),
            $checksumSha256,
        );
    }

    public function completeMultipart(
        MultipartUpload $upload,
        array $orderedParts,
        array $conditions,
    ): StoredFile {
        ++$this->completeCount;
        ksort($this->parts);
        $bytes = implode('', $this->parts);
        $checksum = hash('sha256', $bytes);
        $stored = new StoredFile(
            $upload->organizationPath,
            'version-'.$this->completeCount,
            'etag-'.$this->completeCount,
            strlen($bytes),
            new Sha256Hash($checksum),
            $upload->mime,
        );
        $this->versions[] = [
            'path' => $stored->path,
            'version_id' => $stored->versionId,
            'etag' => $stored->etag,
            'size' => $stored->sizeBytes,
            'sha256' => $stored->checksum->value,
            'mime' => $stored->mime,
            'metadata' => $upload->metadata,
            'created_at' => new DateTimeImmutable('2026-01-01T00:02:00Z'),
        ];

        return $stored;
    }

    public function describeVersion(
        string $path,
        ?string $versionId,
        int $maxBytes = 10485760,
    ): array {
        foreach ($this->versions as $version) {
            if ($version['path'] === $path && $version['version_id'] === $versionId) {
                return [
                    'path' => $version['path'],
                    'body' => '',
                    'size' => $version['size'],
                    'sha256' => $version['sha256'],
                    'etag' => $version['etag'],
                    'version_id' => $version['version_id'],
                    'content_type' => $version['mime'],
                    'metadata' => $version['metadata'],
                ];
            }
        }

        throw new RuntimeException('version missing');
    }

    public function abortMultipart(MultipartUpload $upload): void
    {
        ++$this->abortCount;
    }
}

final readonly class LinkedExportInventory implements ReportArtifactVersionInventory
{
    public function __construct(private RecordingExportFileService $files) {}

    public function forExport(int $organizationId, string $exportId): iterable
    {
        yield from $this->files->versions;
    }
}

final class CountingExportAuthorizer implements CurrentReportExactManyAuthorizer
{
    public int $calls = 0;

    public function __construct(private readonly ReportExecutionContext $context) {}

    public function authorizeExactMany(
        int $actorId,
        ReportScope $requestedScope,
        array $targets,
    ): array {
        ++$this->calls;

        return array_map(
            fn ($target): CurrentReportAuthorization => new CurrentReportAuthorization(
                $this->context->actor,
                $this->context->authorization,
                $this->context->visibility,
                $target,
            ),
            $targets,
        );
    }
}

final class OneRowExportQuery implements ReportRowQuery
{
    public function __construct(private readonly ReportRunExportSource $source) {}

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        throw new RuntimeException('unused');
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        yield [
            'row_key' => 'row-1',
            'values' => ['name' => 'Работа'],
            'snapshot_id' => $snapshot->id,
            'query_hash' => $this->source->query->queryHash->value,
            'source_hash' => $snapshot->sourceHash->value,
        ];
    }
}

final class RecordingExportTelemetry implements ReportExecutionTelemetry
{
    public int $artifacts = 0;

    public function runTransition(string $reportCode, string $status): void {}

    public function runDuration(string $reportCode, string $status, float $seconds): void {}

    public function exportTransition(string $reportCode, string $format, string $status): void {}

    public function exportDuration(string $reportCode, string $format, string $status, float $seconds): void {}

    public function exportArtifact(string $reportCode, string $format, int $rows, int $bytes): void
    {
        ++$this->artifacts;
    }

    public function multipartAbort(string $reportCode, string $format): void {}

    public function dispatchIntent(string $intentType, string $topic, string $outcome, float $ageSeconds): void {}

    public function executionAttempt(string $intentType, string $errorCode): void {}

    public function executionLeaseReclaimed(string $intentType): void {}

    public function auditDeliveryFailure(string $errorCode, string $outcome): void {}
}
