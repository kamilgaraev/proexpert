<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EstimateGenerationDocumentResourceTest extends TestCase
{
    public function test_document_resource_returns_safe_processing_contract(): void
    {
        $document = new EstimateGenerationDocument([
            'filename' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'page_count' => 2,
            'processed_page_count' => 2,
            'quality_score' => 0.86,
            'quality_level' => 'good',
            'quality_flags' => [],
            'facts_summary' => [
                'total_area_m2' => 1280.5,
            ],
            'meta' => [
                'original_extension' => 'pdf',
            ],
            'error_code' => 'provider_error',
            'error_message_key' => 'estimate_generation.documents_upload_error',
        ]);
        $document->id = 15;
        $document->created_at = Carbon::parse('2026-05-29 10:15:30');
        $document->updated_at = Carbon::parse('2026-05-29 10:18:11');

        $payload = (new EstimateGenerationDocumentResource($document))->resolve();

        $this->assertSame(15, $payload['id']);
        $this->assertSame('plan.pdf', $payload['filename']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(100, $payload['progress_percent']);
        $this->assertSame(0.86, $payload['quality']['score']);
        $this->assertSame('good', $payload['quality']['level']);
        $this->assertSame(1280.5, $payload['facts_summary']['total_area_m2']);
        $this->assertSame('provider_error', $payload['error']['code']);
        $this->assertSame('estimate_generation.documents_upload_error', $payload['error']['message_key']);
        $this->assertArrayNotHasKey('storage_path', $payload);
        $this->assertArrayNotHasKey('raw_payload_path', $payload);
    }

    public function test_document_detail_resource_returns_pages_and_facts_without_raw_storage_paths(): void
    {
        $document = new EstimateGenerationDocument([
            'filename' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
        ]);
        $document->id = 15;

        $page = new EstimateGenerationDocumentPage([
            'page_number' => 1,
            'text' => 'Склад 1200 м2',
            'text_hash' => hash('sha256', 'Склад 1200 м2'),
            'confidence' => 0.91,
            'raw_payload_path' => 'org-1/ocr/raw.json',
            'normalized_payload' => [
                'blocks' => [],
            ],
            'quality_flags' => [],
        ]);
        $page->id = 21;

        $fact = new EstimateGenerationDocumentFact([
            'page_id' => 21,
            'fact_type' => 'total_area',
            'scope_key' => 'total_area',
            'label' => 'Общая площадь',
            'value_text' => '1200 м2',
            'value_number' => 1200.0,
            'unit' => 'м2',
            'confidence' => 0.9,
            'source_ref' => [
                'document_id' => 15,
                'page_number' => 1,
            ],
            'normalized_payload' => [
                'line' => 'Склад 1200 м2',
            ],
        ]);
        $fact->id = 31;

        $document->setRelation('pages', collect([$page]));
        $document->setRelation('facts', collect([$fact]));

        $payload = (new EstimateGenerationDocumentDetailResource($document))->resolve();

        $this->assertSame(21, $payload['pages'][0]['id']);
        $this->assertSame('Склад 1200 м2', $payload['pages'][0]['text']);
        $this->assertArrayNotHasKey('raw_payload_path', $payload['pages'][0]);
        $this->assertSame('total_area', $payload['facts'][0]['fact_type']);
        $this->assertSame(1200.0, $payload['facts'][0]['value_number']);
        $this->assertSame(1, $payload['facts'][0]['source_ref']['page_number']);
    }

    public function test_detail_resource_exposes_geometry_source_and_overlay_payload(): void
    {
        $document = new EstimateGenerationDocument([
            'filename' => 'drawing.pdf',
            'mime_type' => 'application/pdf',
            'facts_summary' => [
                'drawing_understanding' => [
                    'review_required_pages' => [],
                    'review_reasons' => [],
                ],
            ],
        ]);
        $document->id = 15;

        $page = new EstimateGenerationDocumentPage([
            'page_number' => 5,
            'normalized_payload' => [
                'geometry' => [
                    'page_role' => 'geometry_only',
                    'visual_metrics' => ['line_count' => 100],
                    'overlay' => [[
                        'type' => 'line',
                        'bbox' => ['x' => 10, 'y' => 10, 'width' => 100, 'height' => 0],
                    ]],
                ],
                'page_understanding' => [
                    'page_role' => 'plan',
                    'role_for_estimation' => 'geometry_source',
                    'review_reasons' => [],
                    'review_required' => false,
                ],
            ],
        ]);
        $page->id = 50;

        $document->setRelation('pages', collect([$page]));

        $payload = (new EstimateGenerationDocumentDetailResource($document))->resolve();

        $this->assertSame('plan', $payload['pages'][0]['page_role']);
        $this->assertSame('geometry_source', $payload['pages'][0]['role_for_estimation']);
        $this->assertSame('geometry_only', $payload['pages'][0]['geometry']['page_role']);
        $this->assertSame(100, $payload['pages'][0]['visual_metrics']['line_count']);
        $this->assertSame([], $payload['pages'][0]['review']['reasons']);
        $this->assertFalse($payload['pages'][0]['review']['required']);
        $this->assertNotEmpty($payload['pages'][0]['overlay']);
    }

    public function test_session_resource_uses_document_resource_contract(): void
    {
        $session = new EstimateGenerationSession([
            'status' => 'created',
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'Склад',
            ],
            'problem_flags' => [],
        ]);
        $session->id = 7;

        $document = new EstimateGenerationDocument([
            'filename' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'queued',
            'processing_stage' => 'ocr_request',
            'progress_percent' => 40,
        ]);
        $document->id = 15;

        $session->setRelation('documents', collect([$document]));

        $payload = (new EstimateGenerationSessionResource($session))->resolve();

        $this->assertSame(15, $payload['documents'][0]['id']);
        $this->assertSame('queued', $payload['documents'][0]['status']);
        $this->assertSame('ocr_request', $payload['documents'][0]['processing_stage']);
        $this->assertArrayHasKey('quality', $payload['documents'][0]);
    }

    public function test_ocr_result_array_hides_raw_payload_by_default(): void
    {
        $result = new OcrRecognitionResult(
            provider: 'timeweb',
            model: 'gemini/gemini-3.1-flash-lite',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: 'Готовый текст',
                    rawPayload: [
                        'provider' => 'raw-page',
                    ],
                ),
            ],
            rawPayload: [
                'provider' => 'raw-document',
            ],
        );

        $safePayload = $result->toArray();
        $rawPayload = $result->toArray(includeRawPayload: true);

        $this->assertArrayNotHasKey('raw_payload', $safePayload);
        $this->assertArrayNotHasKey('raw_payload', $safePayload['pages'][0]);
        $this->assertSame('raw-document', $rawPayload['raw_payload']['provider']);
        $this->assertSame('raw-page', $rawPayload['pages'][0]['raw_payload']['provider']);
    }
}
