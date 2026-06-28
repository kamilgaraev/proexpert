<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class EstimateGenerationDocumentResourceTest extends TestCase
{
    public function test_document_resource_exposes_understanding_summary_counts(): void
    {
        $document = new EstimateGenerationDocument();
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
