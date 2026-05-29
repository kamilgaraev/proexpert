<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EstimateGenerationDocumentUploadTest extends TestCase
{
    public function test_document_upload_stores_original_in_s3_and_dispatches_processing_job(): void
    {
        Storage::fake('s3');
        Queue::fake();

        [$user, , $session] = $this->makeSession();
        $file = UploadedFile::fake()->createWithContent('plan.pdf', '%PDF document content');

        $documents = app(DocumentParsingService::class)->storeParsedDocuments($session, [$file], $user);
        $document = $documents->first();

        $this->assertInstanceOf(EstimateGenerationDocument::class, $document);
        $this->assertSame('queued', $document->status);
        $this->assertSame('stored', $document->processing_stage);
        $this->assertNotNull($document->storage_path);
        $this->assertSame(hash('sha256', '%PDF document content'), $document->checksum_sha256);
        $this->assertStringStartsWith("org-{$session->organization_id}/estimate-generation/sessions/{$session->id}/documents/", $document->storage_path);
        Storage::disk('s3')->assertExists($document->storage_path);

        Queue::assertPushed(
            ProcessEstimateGenerationDocumentJob::class,
            static fn (ProcessEstimateGenerationDocumentJob $job): bool => $job->queue === ProcessEstimateGenerationDocumentJob::QUEUE
                && $job->connection === ProcessEstimateGenerationDocumentJob::CONNECTION
        );
    }

    public function test_document_processor_reads_from_s3_and_persists_pages(): void
    {
        Storage::fake('s3');

        [, , $session] = $this->makeSession();
        $storagePath = "org-{$session->organization_id}/estimate-generation/sessions/{$session->id}/documents/plan.pdf";
        Storage::disk('s3')->put($storagePath, '%PDF document content');

        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'user_id' => $session->user_id,
            'filename' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => $storagePath,
            'status' => 'queued',
            'processing_stage' => 'stored',
            'progress_percent' => 0,
            'file_size_bytes' => 21,
            'checksum_sha256' => hash('sha256', '%PDF document content'),
            'structured_payload' => [],
            'meta' => [
                'original_extension' => 'pdf',
            ],
        ]);

        $this->app->instance(OcrClientInterface::class, new class implements OcrClientInterface {
            public function recognize(OcrDocumentInput $input): OcrRecognitionResult
            {
                return new OcrRecognitionResult(
                    provider: 'test_ocr',
                    model: 'page',
                    pages: [
                        new OcrPageResult(
                            pageNumber: 1,
                            text: 'Общая площадь здания 1200 м2',
                            blocks: [],
                            confidence: 0.91,
                            languageCodes: ['ru'],
                        ),
                    ],
                );
            }
        });

        app(OcrDocumentProcessor::class)->process($document);
        $document->refresh();

        $this->assertSame('ready', $document->status);
        $this->assertSame('completed', $document->processing_stage);
        $this->assertSame(100, $document->progress_percent);
        $this->assertSame('Общая площадь здания 1200 м2', $document->extracted_text);
        $this->assertSame('test_ocr', $document->ocr_provider);
        $this->assertSame(1, $document->page_count);
        $this->assertSame(1, $document->processed_page_count);
        $this->assertEquals(1200.0, $document->facts_summary['total_area_m2']);
        $this->assertDatabaseHas('estimate_generation_document_pages', [
            'document_id' => $document->id,
            'page_number' => 1,
            'text' => 'Общая площадь здания 1200 м2',
        ]);
        $this->assertDatabaseHas('estimate_generation_document_facts', [
            'document_id' => $document->id,
            'fact_type' => 'total_area',
            'value_number' => 1200.0000,
        ]);
    }

    public function test_document_processor_extracts_spreadsheet_without_ocr_provider_call(): void
    {
        Storage::fake('s3');

        [, , $session] = $this->makeSession();
        $content = $this->spreadsheetContent([
            ['Общая площадь здания', '1280 м2'],
            ['Складская зона', '980 м2'],
            ['Офисная зона', '300 м2'],
            ['Этажность', '2 этажа'],
            ['Инженерные системы', 'электроснабжение, вентиляция'],
        ]);
        $storagePath = "org-{$session->organization_id}/estimate-generation/sessions/{$session->id}/documents/scope.xlsx";
        Storage::disk('s3')->put($storagePath, $content);

        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'user_id' => $session->user_id,
            'filename' => 'scope.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => $storagePath,
            'status' => 'queued',
            'processing_stage' => 'stored',
            'progress_percent' => 0,
            'file_size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'structured_payload' => [],
            'meta' => [
                'original_extension' => 'xlsx',
            ],
        ]);

        $this->app->instance(OcrClientInterface::class, new class implements OcrClientInterface {
            public function recognize(OcrDocumentInput $input): OcrRecognitionResult
            {
                throw new \RuntimeException('OCR provider must not be called for spreadsheets.');
            }
        });

        app(OcrDocumentProcessor::class)->process($document);
        $document->refresh();

        $this->assertSame('ready', $document->status);
        $this->assertSame(SpreadsheetDocumentExtractor::PROVIDER, $document->ocr_provider);
        $this->assertSame(SpreadsheetDocumentExtractor::MODEL, $document->ocr_model);
        $this->assertEquals(1280.0, $document->facts_summary['total_area_m2']);
        $this->assertCount(2, $document->facts_summary['zones']);
        $this->assertDatabaseHas('estimate_generation_document_pages', [
            'document_id' => $document->id,
            'page_number' => 1,
        ]);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeSession(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'Склад',
            ],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function spreadsheetContent(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $cell = Coordinate::stringFromColumnIndex($columnIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($cell, $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'estimate-generation-document-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $content = file_get_contents($path);
        unlink($path);

        return $content === false ? '' : $content;
    }
}
