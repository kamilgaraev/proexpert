<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ArtifactDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentManifestNeedsReview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceManifestStorage;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\InMemoryDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\MetadataDocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\S3DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use DateTimeImmutable;
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

        self::assertTrue($first->acquired);
        self::assertFalse($second->acquired);
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
        )->acquired);
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

        self::assertFalse($claim->acquired);
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
    public function legacy_document_job_is_dispatcher_only(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationDocumentJob.php');

        self::assertIsString($source);
        self::assertStringContainsString('CreateDocumentProcessingUnits', $source);
        self::assertStringNotContainsString('OcrDocumentProcessor', $source);
        self::assertStringNotContainsString('FileService', $source);
        self::assertStringNotContainsString('->get(', $source);
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
        $usecase = new ProcessDocumentUnit($store, $processor, $reconciler);

        $usecase->handle($unit->id, 'source');
        $usecase->handle($unit->id, 'source');

        self::assertSame(1, $processor->calls);
        self::assertSame(1, $reconciler->calls);
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
        $usecase = new ProcessDocumentUnit($store, $processor, $reconciler);

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
            (new ProcessDocumentUnit($store, $processor, $reconciler))->handle($unit->id, 'source');
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
        $document = new EstimateGenerationDocument(['filename' => 'house.pdf', 'mime_type' => 'application/pdf', 'page_count' => 200]);
        $detector = new ArtifactDocumentUnitDetector($storage, $pdf, $spreadsheet, new MetadataDocumentUnitDetector);

        $units = $detector->detect($document, 'sha256:source');

        self::assertCount(200, $units);
        self::assertSame(1, $storage->reads);
        self::assertCount(200, $storage->paths);
        self::assertSame(range(1, 200), array_column($units, 'index'));
        self::assertSame('org-10/manifest/pdf_page-200.txt', $units[199]->locator['artifact_path']);
    }

    #[Test]
    public function scanned_pdf_without_text_manifest_requires_review_before_dispatch(): void
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
        $detector = new ArtifactDocumentUnitDetector($storage, $pdf, $spreadsheet, new MetadataDocumentUnitDetector);
        $document = new EstimateGenerationDocument(['filename' => 'scan.pdf', 'mime_type' => 'application/pdf', 'page_count' => 30]);

        try {
            $detector->detect($document, 'sha256:scan');
            self::fail('Scanned PDF must require a page renderer.');
        } catch (DocumentManifestNeedsReview $error) {
            self::assertSame('pdf_page_renderer_required', $error->safeCode);
        }

        self::assertSame(0, $storage->writes);
    }

    #[Test]
    public function unit_reader_rejects_another_organization_artifact_path(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('estimate_generation.document_unit_scope_invalid');

        S3DocumentUnitContentReader::assertOrganizationPath('org-99/manifest/page.txt', 10);
    }
}
