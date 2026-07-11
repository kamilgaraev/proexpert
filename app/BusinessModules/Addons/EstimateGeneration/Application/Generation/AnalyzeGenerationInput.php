<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;

final readonly class AnalyzeGenerationInput
{
    public function __construct(private ConstructionSemanticParser $parser, private AdvanceEstimateGeneration $advance) {}

    public function handle(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $documents = $session->documents()
            ->with(['facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'])
            ->get()
            ->map(static fn ($document): array => [
                'id' => (int) $document->id,
                'status' => (string) $document->status,
                'structured_payload' => $document->structured_payload ?? [],
                'facts_summary' => $document->facts_summary ?? [],
                'quality' => ['score' => $document->quality_score, 'level' => $document->quality_level, 'flags' => $document->quality_flags ?? []],
                'facts' => $document->facts->map(static fn ($fact): array => $fact->toArray())->all(),
                'drawing_elements' => $document->drawingElements->map(static fn ($element): array => $element->toArray())->all(),
                'quantity_takeoffs' => $document->quantityTakeoffs->map(static fn ($takeoff): array => $takeoff->toArray())->all(),
                'scope_inferences' => $document->scopeInferences->map(static fn ($inference): array => $inference->toArray())->all(),
            ])->all();

        return $this->advance->documentsReady($session, [
            'processing_stage' => ProcessingStage::UnderstandObject->value,
            'processing_progress' => 35,
            'analysis_payload' => $this->parser->parse($session->input_payload ?? [], $documents),
            'last_error' => null,
        ]);
    }
}
