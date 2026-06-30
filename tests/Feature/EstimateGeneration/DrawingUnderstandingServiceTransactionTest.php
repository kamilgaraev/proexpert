<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Contracts\DrawingAnalysisProviderInterface;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDrawingElement;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingUnderstandingService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;
use Throwable;

final class DrawingUnderstandingServiceTransactionTest extends TestCase
{
    public function test_existing_drawing_takeoffs_remain_when_new_persistence_fails(): void
    {
        [$document] = $this->makeDocument();

        EstimateGenerationDrawingElement::query()->create([
            'session_id' => $document->session_id,
            'document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'type' => 'room',
            'label' => 'old-room',
            'confidence' => 0.9,
            'source_ref' => ['page_number' => 1, 'line_hash' => 'old-element'],
            'normalized_payload' => [],
        ]);

        EstimateGenerationQuantityTakeoff::query()->create([
            'session_id' => $document->session_id,
            'document_id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'source_element_ids' => [],
            'work_intent' => [],
            'name' => 'old-takeoff',
            'unit' => 'm2',
            'quantity' => 12.5,
            'confidence' => 0.9,
            'source_refs' => [['page_number' => 1, 'line_hash' => 'old-takeoff']],
            'normalized_payload' => [],
        ]);

        $service = new DrawingUnderstandingService(new FailingDrawingAnalysisProvider());

        try {
            $service->analyzeAndPersist($document, $this->makeRecognition(), []);
            self::fail('Drawing persistence failure was expected.');
        } catch (Throwable) {
        }

        self::assertSame(1, $document->drawingElements()->where('label', 'old-room')->count());
        self::assertSame(1, $document->quantityTakeoffs()->where('name', 'old-takeoff')->count());
        self::assertSame(0, $document->drawingElements()->where('label', 'new-room')->count());
        self::assertSame(0, $document->quantityTakeoffs()->where('scope_key', 'new.scope')->count());
    }

    /**
     * @return array{0: EstimateGenerationDocument}
     */
    private function makeDocument(): array
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
            'status' => 'ready',
            'processing_stage' => 'ready',
            'processing_progress' => 100,
            'input_payload' => [
                'description' => 'house plan',
            ],
            'problem_flags' => [],
        ]);

        return [
            EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'user_id' => $session->user_id,
                'filename' => 'plan.jpg',
                'mime_type' => 'image/jpeg',
                'storage_path' => 'org-' . $session->organization_id . '/estimate-generation/documents/plan.jpg',
                'status' => 'ready',
                'processing_stage' => 'ready',
                'progress_percent' => 100,
                'page_count' => 1,
                'processed_page_count' => 1,
                'quality_flags' => [],
                'facts_summary' => [],
            ]),
        ];
    }

    private function makeRecognition(): OcrRecognitionResult
    {
        return new OcrRecognitionResult(
            provider: 'test',
            model: 'page',
            pages: [
                new OcrPageResult(
                    pageNumber: 1,
                    text: 'room 12.5 m2',
                    confidence: 0.9,
                ),
            ],
        );
    }
}

final class FailingDrawingAnalysisProvider implements DrawingAnalysisProviderInterface
{
    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): DrawingAnalysisResultData
    {
        return new DrawingAnalysisResultData(
            elements: [
                [
                    'type' => 'room',
                    'label' => 'new-room',
                    'confidence' => 0.8,
                    'source_ref' => ['page_number' => 1, 'line_hash' => 'new-element'],
                    'normalized_payload' => [],
                ],
            ],
            takeoffs: [
                [
                    'scope_key' => 'new.scope',
                    'work_intent' => [],
                    'name' => null,
                    'unit' => 'm2',
                    'quantity' => 10.0,
                    'confidence' => 0.8,
                    'source_refs' => [['page_number' => 1, 'line_hash' => 'new-takeoff']],
                    'normalized_payload' => [],
                ],
            ],
        );
    }
}
