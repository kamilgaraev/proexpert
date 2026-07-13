<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ArtifactDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DispatchDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExhaustionHandler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EstimateGenerationUnitJobDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\InMemoryDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\MetadataDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryWorker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentProcessingUnitContractTest extends TestCase
{
    #[Test]
    public function duplicate_detector_units_are_normalized_to_one_stable_identity(): void
    {
        $unit = new DocumentUnitData(DocumentUnitType::PdfPage, 1, 'sha256:source', ['page' => 1]);

        $normalized = DocumentUnitData::normalize([$unit, $unit]);

        self::assertCount(1, $normalized);
        self::assertSame('pdf_page:1:sha256:source', $normalized[0]->identity());
    }

    #[Test]
    public function unit_indexes_are_positive_and_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DocumentUnitData(DocumentUnitType::RasterImage, 0, 'sha256:source');
    }

    #[Test]
    public function only_one_worker_can_claim_a_unit_and_expired_owner_cannot_publish(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(10, 20, 30, 40, new DocumentUnitData(
            DocumentUnitType::Sketch,
            1,
            'sha256:source',
        ));
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');

        $first = $store->claim($unit->id, 'sha256:source', $now, $now->modify('+30 seconds'), 3);
        $second = $store->claim($unit->id, 'sha256:source', $now, $now->modify('+30 seconds'), 3);

        self::assertTrue($first->acquired());
        self::assertFalse($second->acquired());
        self::assertFalse($store->complete($first, 'v1', 1, $now->modify('+31 seconds')));
        self::assertSame(DocumentProcessingUnitStatus::Running, $store->find($unit->id)?->status);
    }

    #[Test]
    public function duplicate_delivery_reuses_completed_output(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(10, 20, 30, 40, new DocumentUnitData(
            DocumentUnitType::SpreadsheetSheet,
            1,
            'sha256:source',
        ));
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $claim = $store->claim($unit->id, 'sha256:source', $now, $now->modify('+30 seconds'), 3);

        self::assertTrue($store->complete($claim, 'output-v1', 1, $now->modify('+1 second')));
        self::assertFalse($store->claim(
            $unit->id,
            'sha256:source',
            $now->modify('+2 seconds'),
            $now->modify('+32 seconds'),
            3,
        )->acquired());
        self::assertSame('output-v1', $store->find($unit->id)?->outputVersion);
        self::assertSame(1, $store->find($unit->id)?->outputCount);
    }

    #[Test]
    public function replaced_source_makes_old_units_stale_and_non_claimable(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $old = $store->create(10, 20, 30, 40, new DocumentUnitData(DocumentUnitType::TextPage, 1, 'old'));

        $store->supersedeDocumentSource(40, 'new');
        $claim = $store->claim(
            $old->id,
            'old',
            new DateTimeImmutable('2026-07-11T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-11T10:01:00+00:00'),
            3,
        );

        self::assertFalse($claim->acquired());
        self::assertSame(DocumentProcessingUnitStatus::Superseded, $store->find($old->id)?->status);
    }

    #[Test]
    public function multipage_pdf_and_multiframe_sketch_have_bounded_stable_units(): void
    {
        $detector = new MetadataDocumentUnitDetector;
        $pdf = new EstimateGenerationDocument([
            'filename' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'page_count' => 3,
        ]);
        $sketch = new EstimateGenerationDocument([
            'filename' => 'sketch.tiff',
            'mime_type' => 'image/tiff',
            'meta' => ['frame_count' => 2, 'is_sketch' => true],
        ]);

        $pdfUnits = $detector->detect($pdf, 'sha256:pdf');
        $sketchUnits = $detector->detect($sketch, 'sha256:sketch');

        self::assertSame([1, 2, 3], array_column($pdfUnits, 'index'));
        self::assertSame(DocumentUnitType::PdfPage, $pdfUnits[0]->type);
        self::assertSame([1, 2], array_column($sketchUnits, 'index'));
        self::assertSame(DocumentUnitType::Sketch, $sketchUnits[0]->type);

        $workbook = new EstimateGenerationDocument([
            'filename' => 'estimate.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'meta' => ['sheet_count' => 75],
        ]);
        $sheetUnits = $detector->detect($workbook, 'sha256:workbook');

        self::assertCount(75, $sheetUnits);
        self::assertSame(range(1, 75), array_column($sheetUnits, 'index'));
        self::assertSame(DocumentUnitType::SpreadsheetSheet, $sheetUnits[0]->type);
    }

    #[Test]
    public function document_job_delegates_to_one_application_entrypoint(): void
    {
        $root = __DIR__.'/../../../app/BusinessModules/Addons/EstimateGeneration';
        $source = file_get_contents($root.'/Jobs/ProcessEstimateGenerationDocumentJob.php');
        $entrypoint = file_get_contents($root.'/Application/Documents/ProcessEstimateGenerationDocument.php');

        self::assertIsString($source);
        self::assertIsString($entrypoint);
        self::assertStringContainsString('ProcessEstimateGenerationDocument', $source);
        self::assertStringContainsString('$documents->handle(', $source);
        self::assertStringNotContainsString('OcrDocumentProcessor', $source);
        self::assertStringNotContainsString('FileService', $source);
        self::assertStringNotContainsString('PipelineCheckpointStore', $source);
        self::assertStringContainsString('CreateDocumentProcessingUnits', $entrypoint);
        self::assertStringContainsString('PipelineCheckpointStore', $entrypoint);
        self::assertStringContainsString('PipelineStageOutput::create(', $entrypoint);
        self::assertStringContainsString('document_manifest_v1', $entrypoint);
        self::assertStringNotContainsString("->with(['facts'", $source);
        self::assertStringNotContainsString('readStream', $source);
    }

    #[Test]
    public function duplicate_usecase_delivery_processes_publishes_and_reconciles_once(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::Sketch, 1, 'source'));
        $processor = new class implements DocumentUnitProcessor
        {
            public int $calls = 0;

            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                $this->calls++;

                return new DocumentUnitOutput('output', 'recognized');
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public int $calls = 0;

            public function reconcile(int $documentId, string $sourceVersion): void
            {
                $this->calls++;
            }
        };
        $usecase = $this->processUnit($store, $processor, $reconciler);

        $usecase->handle($unit->id, 'source');
        $usecase->handle($unit->id, 'source');

        self::assertSame(1, $processor->calls);
        self::assertSame(2, $reconciler->calls);
        self::assertSame('output', $store->find($unit->id)?->outputVersion);
        self::assertSame(1, $store->find($unit->id)?->outputCount);
    }

    #[Test]
    public function failed_unit_retries_only_that_unit(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::RasterImage, 1, 'source'));
        $processor = new class implements DocumentUnitProcessor
        {
            public int $calls = 0;

            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('secret source contents');
                }

                return new DocumentUnitOutput('retry-output', 'recognized');
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public int $calls = 0;

            public function reconcile(int $documentId, string $sourceVersion): void
            {
                $this->calls++;
            }
        };
        $usecase = $this->processUnit($store, $processor, $reconciler);

        try {
            $usecase->handle($unit->id, 'source');
            self::fail('First attempt must fail.');
        } catch (\RuntimeException) {
        }
        self::assertSame('unit_processing_failed', $store->find($unit->id)?->failureCode);
        self::assertNotSame('secret source contents', $store->find($unit->id)?->failureCode);
        $usecase->handle($unit->id, 'source');

        self::assertSame(2, $processor->calls);
        self::assertSame(1, $reconciler->calls);
        self::assertSame(2, $store->find($unit->id)?->attemptCount);
        self::assertSame('retry-output', $store->find($unit->id)?->outputVersion);
        self::assertNull($store->find($unit->id)?->failureCode);
    }

    #[Test]
    public function unit_lease_outlives_worker_timeout(): void
    {
        $job = new ProcessEstimateGenerationUnitJob(1, 'source');

        self::assertGreaterThan($job->timeout + 120, ProcessDocumentUnit::LEASE_SECONDS);
    }

    #[Test]
    public function mismatched_unit_output_is_rejected_without_publication(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::PdfPage, 1, 'source'));
        $processor = new class implements DocumentUnitProcessor
        {
            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                return new DocumentUnitOutput(
                    'output',
                    'wrong page',
                    unitType: DocumentUnitType::PdfPage,
                    unitIndex: 2,
                    sourceVersion: 'source',
                );
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public function reconcile(int $documentId, string $sourceVersion): void {}
        };

        try {
            $this->processUnit($store, $processor, $reconciler)->handle($unit->id, 'source');
            self::fail('Mismatched output must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('unit_output_identity_mismatch', $error->getMessage());
        }

        self::assertSame(DocumentProcessingUnitStatus::Failed, $store->find($unit->id)?->status);
        self::assertNull($store->find($unit->id)?->outputVersion);
    }

    #[Test]
    public function two_hundred_page_manifest_reads_source_once_and_writes_scoped_artifacts(): void
    {
        $storage = new class implements DocumentSourceManifestStorage
        {
            public int $reads = 0;

            /** @var list<string> */
            public array $paths = [];

            public function read(EstimateGenerationDocument $document): string
            {
                $this->reads++;

                return 'pdf-source';
            }

            public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content): string
            {
                return $this->paths[] = sprintf('org-10/manifest/%s-%d.txt', $type->value, $index);
            }
        };
        $pages = array_map(
            static fn (int $index): OcrPageResult => new OcrPageResult($index, 'page '.$index),
            range(1, 200),
        );
        $pdf = new class($pages) extends PdfTextLayerExtractor
        {
            public function __construct(private array $results) {}

            public function extract(string $content, ?string $filename = null): ?OcrRecognitionResult
            {
                return new OcrRecognitionResult('pdf_text', 'v1', $this->results);
            }
        };
        $spreadsheet = new class extends SpreadsheetDocumentExtractor
        {
            public function extract(EstimateGenerationDocument $document, string $content): OcrRecognitionResult
            {
                return new OcrRecognitionResult('sheet', 'v1', [new OcrPageResult(1, 'sheet')]);
            }
        };
        $geometry = new PdfGeometryExtractor(new class extends PdfGeometryWorker
        {
            public function extract(string $content, ?string $filename = null): array
            {
                return ['provider' => 'test', 'model' => 'geometry_v1', 'pages' => array_map(static fn (int $page): array => [
                    'page_number' => $page, 'width' => 100, 'height' => 100, 'rotation' => 0,
                    'text_blocks' => [['text' => 'page '.$page]], 'vector_elements' => [],
                    'visual_metrics' => [], 'page_role' => 'plan', 'signals' => [],
                    'preview' => ['content_base64' => base64_encode('png-pdf-source'), 'sha256' => hash('sha256', 'png-pdf-source')],
                ], range(1, 200)), 'metadata' => []];
            }
        });
        $document = new EstimateGenerationDocument(['filename' => 'house.pdf', 'mime_type' => 'application/pdf', 'page_count' => 200]);
        $detector = new ArtifactDocumentUnitDetector($storage, $pdf, $geometry, $spreadsheet, new MetadataDocumentUnitDetector);

        $units = $detector->detect($document, 'sha256:source');

        self::assertCount(200, $units);
        self::assertSame(1, $storage->reads);
        self::assertCount(400, $storage->paths);
        self::assertSame(range(1, 200), array_column($units, 'index'));
        self::assertSame('org-10/manifest/sketch-200.txt', $units[199]->locator['artifact_path']);
        self::assertSame('org-10/manifest/pdf_page-200.txt', $units[199]->locator['geometry_artifact_path']);
    }

    #[Test]
    public function scanned_pdf_is_rendered_to_bounded_page_artifact_before_dispatch(): void
    {
        $storage = new class implements DocumentSourceManifestStorage
        {
            public int $writes = 0;

            public function read(EstimateGenerationDocument $document): string
            {
                return 'scanned-pdf';
            }

            public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content): string
            {
                $this->writes++;

                return 'org-10/never';
            }
        };
        $pdf = new class extends PdfTextLayerExtractor
        {
            public function __construct() {}

            public function extract(string $content, ?string $filename = null): ?OcrRecognitionResult
            {
                return null;
            }
        };
        $spreadsheet = new class extends SpreadsheetDocumentExtractor {};
        $geometry = new PdfGeometryExtractor(new class extends PdfGeometryWorker
        {
            public function extract(string $content, ?string $filename = null): array
            {
                return ['provider' => 'test', 'model' => 'geometry_v1', 'pages' => [[
                    'page_number' => 1, 'width' => 100, 'height' => 100, 'rotation' => 0,
                    'text_blocks' => [], 'vector_elements' => [], 'visual_metrics' => [],
                    'page_role' => 'empty', 'signals' => [],
                    'preview' => ['content_base64' => base64_encode('rendered-png'), 'sha256' => hash('sha256', 'rendered-png')],
                ]], 'metadata' => []];
            }
        });
        $detector = new ArtifactDocumentUnitDetector($storage, $pdf, $geometry, $spreadsheet, new MetadataDocumentUnitDetector);
        $document = new EstimateGenerationDocument(['filename' => 'scan.pdf', 'mime_type' => 'application/pdf', 'page_count' => 30]);

        $units = $detector->detect($document, 'sha256:scan');

        self::assertCount(1, $units);
        self::assertSame('image/png', $units[0]->locator['content_type']);
        self::assertSame('sha256:'.hash('sha256', 'rendered-png'), $units[0]->locator['artifact_source_version']);
        self::assertSame(2, $storage->writes);
    }

    #[Test]
    public function unit_reader_rejects_another_organization_artifact_path(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('document_storage_scope_invalid');

        S3DocumentUnitContentReader::assertOrganizationPath('org-99/manifest/page.txt', 10);
    }

    #[Test]
    public function published_unit_is_not_failed_when_finalization_throws_and_retry_only_finalizes(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::PdfPage, 1, 'source'));
        $processor = new class implements DocumentUnitProcessor
        {
            public int $calls = 0;

            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                $this->calls++;

                return new DocumentUnitOutput('output', 'page');
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public int $calls = 0;

            public function reconcile(int $documentId, string $sourceVersion): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('finalizer unavailable');
                }
            }
        };
        $usecase = $this->processUnit($store, $processor, $reconciler);

        try {
            $usecase->handle($unit->id, 'source');
            self::fail('First finalization must fail.');
        } catch (\RuntimeException) {
        }

        self::assertSame(DocumentProcessingUnitStatus::Completed, $store->find($unit->id)?->status);
        self::assertNull($store->find($unit->id)?->failureCode);
        $outcome = $usecase->handle($unit->id, 'source');

        self::assertSame(DocumentProcessingUnitClaimStatus::AlreadyCompleted, $outcome->status);
        self::assertSame(1, $processor->calls);
        self::assertSame(2, $reconciler->calls);
    }

    #[Test]
    public function durable_dispatch_repair_requeues_candidates_after_partial_enqueue(): void
    {
        $store = new class implements DocumentUnitDispatchStore
        {
            /** @var list<DocumentUnitDispatchCandidate> */
            public array $due;

            /** @var list<int> */
            public array $marked = [];

            public function __construct()
            {
                $this->due = [new DocumentUnitDispatchCandidate(1, 'source'), new DocumentUnitDispatchCandidate(2, 'source')];
            }

            public function dueForDocument(int $documentId, string $sourceVersion, DateTimeImmutable $now, int $limit): array
            {
                return $this->due;
            }

            public function dueForRecovery(DateTimeImmutable $now, int $limit): array
            {
                return array_values(array_filter($this->due, fn (DocumentUnitDispatchCandidate $candidate): bool => ! in_array($candidate->unitId, $this->marked, true)));
            }

            public function markDispatched(int $unitId, DateTimeImmutable $now, DateTimeImmutable $nextDispatchAt): void
            {
                $this->marked[] = $unitId;
            }
        };
        $jobs = new class implements EstimateGenerationUnitJobDispatcher
        {
            /** @var list<int> */
            public array $ids = [];

            public bool $failSecond = true;

            public function dispatch(int $unitId, string $sourceVersion): void
            {
                if ($unitId === 2 && $this->failSecond) {
                    throw new \RuntimeException('queue unavailable');
                }

                $this->ids[] = $unitId;
            }
        };
        $dispatcher = new DispatchDocumentProcessingUnits($store, $jobs);

        try {
            $dispatcher->forDocument(4, 'source');
            self::fail('Partial enqueue must expose transient queue failure.');
        } catch (\RuntimeException) {
        }
        $jobs->failSecond = false;
        $recovered = $dispatcher->recover();

        self::assertSame(1, $recovered);
        self::assertSame([1, 2], $jobs->ids);
        self::assertSame([1, 2], $store->marked);
    }

    #[Test]
    public function busy_unit_is_released_until_lease_then_reclaimed(): void
    {
        Carbon::setTestNow('2026-07-11 10:00:00');
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::Sketch, 1, 'source'));
        $now = Carbon::now()->toDateTimeImmutable();
        $store->claim($unit->id, 'source', $now, $now->modify('+60 seconds'), 3);
        $processor = new class implements DocumentUnitProcessor
        {
            public int $calls = 0;

            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                $this->calls++;

                return new DocumentUnitOutput('output', 'sketch');
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public function reconcile(int $documentId, string $sourceVersion): void {}
        };
        $usecase = $this->processUnit($store, $processor, $reconciler);

        $busy = $usecase->handle($unit->id, 'source');
        self::assertSame(DocumentProcessingUnitClaimStatus::Busy, $busy->status);
        self::assertNotNull($busy->retryAt);
        self::assertSame(0, $processor->calls);

        Carbon::setTestNow('2026-07-11 10:01:01');
        $completed = $usecase->handle($unit->id, 'source');
        Carbon::setTestNow();

        self::assertSame(DocumentProcessingUnitClaimStatus::Acquired, $completed->status);
        self::assertSame(1, $processor->calls);
        self::assertSame(2, $store->find($unit->id)?->attemptCount);
    }

    #[Test]
    public function exhausted_unit_invokes_actionable_handler_instead_of_silent_success(): void
    {
        $store = new InMemoryDocumentProcessingUnitStore;
        $unit = $store->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::RasterImage, 1, 'source'));
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');

        for ($attempt = 0; $attempt < ProcessDocumentUnit::MAX_ATTEMPTS; $attempt++) {
            $claim = $store->claim($unit->id, 'source', $now, $now->modify('+60 seconds'), ProcessDocumentUnit::MAX_ATTEMPTS);
            $store->fail($claim, 'failed', hash('sha256', 'failed'), $now->modify('+1 second'));
        }

        $processor = new class implements DocumentUnitProcessor
        {
            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                throw new \LogicException('must not process exhausted unit');
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public function reconcile(int $documentId, string $sourceVersion): void {}
        };
        $handler = new class implements DocumentUnitExhaustionHandler
        {
            public int $calls = 0;

            public function handle(int $unitId): void
            {
                $this->calls++;
            }
        };

        $outcome = $this->processUnit($store, $processor, $reconciler, $handler)->handle($unit->id, 'source');

        self::assertSame(DocumentProcessingUnitClaimStatus::Exhausted, $outcome->status);
        self::assertSame(1, $handler->calls);
    }

    #[Test]
    public function production_store_and_finalizer_keep_connection_and_source_fences(): void
    {
        $store = file_get_contents(__DIR__.'/../../../app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentProcessingUnitStore.php');
        $finalizer = file_get_contents(__DIR__.'/../../../app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php');
        $provider = file_get_contents(__DIR__.'/../../../app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');

        self::assertIsString($store);
        self::assertStringNotContainsString('EstimateGenerationProcessingUnit::query()', $store);
        self::assertStringNotContainsString('EstimateGenerationDocumentPage::query()', $store);
        self::assertStringContainsString('setConnection($this->database->getName())', $store);
        self::assertStringContainsString("->where('organization_id', \$unit->organization_id)", $store);
        self::assertStringContainsString("->where('source_version', \$unit->source_version)", $store);
        self::assertIsString($finalizer);
        self::assertStringContainsString("->whereIn('processing_unit_id', \$currentUnitIds)", $finalizer);
        self::assertStringContainsString("->where('source_version', \$sourceVersion)", $finalizer);
        self::assertStringContainsString("whereNotIn('processing_unit_id', \$currentUnitIds)", $finalizer);
        self::assertStringContainsString("'units_finalized_source_version' => \$sourceVersion", $finalizer);
        self::assertIsString($provider);
        self::assertStringContainsString('RecoverEstimateGenerationUnitsJob', $provider);
        self::assertStringContainsString('->everyMinute()', $provider);
    }

    private function processUnit(
        InMemoryDocumentProcessingUnitStore $store,
        DocumentUnitProcessor $processor,
        DocumentUnitAggregateReconciler $reconciler,
        ?DocumentUnitExhaustionHandler $exhaustion = null,
    ): ProcessDocumentUnit {
        $failures = new class implements FailureStore
        {
            public function record(FailureData $failure, DateTimeImmutable $seenAt): void {}

            public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
            {
                return false;
            }

            public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
            {
                return 0;
            }
        };
        $workflow = new class implements FailureWorkflowHandler
        {
            public function handle(FailureData $failure, ?int $expectedStateVersion = null): void {}
        };

        return new ProcessDocumentUnit($store, $processor, $reconciler, new FailureRecorder($failures), $workflow, $exhaustion);
    }
}
