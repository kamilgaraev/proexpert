<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use Illuminate\Http\Request;
use Tests\Support\DatabaseLessTestCase;

class EstimateGenerationDocumentResourceTest extends DatabaseLessTestCase
{
    public function test_detail_original_version_uses_checksum_and_never_processing_control(): void
    {
        foreach ([str_repeat('a', 64), null] as $checksum) {
            $document = new EstimateGenerationDocument;
            $document->forceFill(['id' => 15, 'filename' => 'plan.pdf', 'mime_type' => 'application/pdf',
                'status' => 'ready', 'processing_stage' => 'completed', 'progress_percent' => 100,
                'page_count' => 1, 'processed_page_count' => 1, 'quality_flags' => [], 'facts_summary' => [], 'meta' => [],
                'checksum_sha256' => $checksum, 'processing_control_source_version' => 'sha256:'.str_repeat('b', 64)]);
            $payload = (new \App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource($document))
                ->toArray(Request::create('/'));
            self::assertSame($checksum === null ? null : 'sha256:'.$checksum, $payload['document_source_version']);
            self::assertNotSame($payload['processing_control']['source_version'], $payload['document_source_version']);
        }
    }

    public function test_document_resource_exposes_understanding_summary_counts(): void
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 15,
            'filename' => 'АР-1.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'page_count' => 12,
            'processed_page_count' => 12,
            'quality_flags' => [],
            'facts_summary' => [],
            'meta' => [],
        ]);
        $document->setAttribute('pages_count', 12);
        $document->setAttribute('facts_count', 8);
        $document->setAttribute('drawing_elements_count', 21);
        $document->setAttribute('quantity_takeoffs_count', 7);
        $document->setAttribute('scope_inferences_count', 5);

        $payload = (new EstimateGenerationDocumentResource($document))->toArray(Request::create('/'));

        self::assertSame([
            'pages' => 12,
            'facts' => 8,
            'drawing_elements' => 21,
            'quantity_takeoffs' => 7,
            'scope_inferences' => 5,
        ], $payload['understanding_summary']);
    }
}
