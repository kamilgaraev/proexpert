<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Arr;

class EstimateGenerationOrchestrator
{
    public function __construct(
        protected ConstructionSemanticParser $semanticParser,
        protected EstimateDecompositionService $decompositionService,
        protected WorkItemGenerationService $workItemGenerationService,
        protected ResourceAssemblyService $resourceAssemblyService,
        protected EstimatePricingService $pricingService,
        protected EstimateValidationService $validationService,
    ) {}

    public function analyze(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $documents = $session->documents()
            ->get(['filename', 'extracted_text', 'structured_payload'])
            ->map(static fn ($document): array => $document->toArray())
            ->all();

        $analysis = $this->semanticParser->parse($session->input_payload ?? [], $documents);

        $session->forceFill([
            'status' => 'analyzed',
            'processing_stage' => 'object_understanding',
            'processing_progress' => 35,
            'analysis_payload' => $analysis,
            'last_error' => null,
        ])->save();

        return $session->fresh(['documents']);
    }

    public function generate(EstimateGenerationSession $session): EstimateGenerationSession
    {
        if (($session->analysis_payload ?? []) === []) {
            $session = $this->analyze($session);
        }

        $analysis = $session->analysis_payload ?? [];
        $localEstimates = $this->decompositionService->decompose($analysis);

        foreach ($localEstimates as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $workItems = $this->workItemGenerationService->build($localEstimate, $analysis);
                $workItems = $this->resourceAssemblyService->enrich($workItems);
                $workItems = $this->pricingService->price($workItems);
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $workItems;
            }
        }

        $draft = [
            'title' => $session->input_payload['description'] ?? 'AI draft estimate',
            'source_documents' => Arr::get($analysis, 'source_documents', []),
            'local_estimates' => $localEstimates,
            'traceability' => [
                'analysis' => Arr::get($analysis, 'detected_structure', []),
            ],
        ];

        $draft = $this->validationService->validate($draft);

        $session->forceFill([
            'status' => 'generated',
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'draft_payload' => $draft,
            'problem_flags' => $draft['problem_flags'] ?? [],
            'last_error' => null,
        ])->save();

        return $session->fresh(['documents']);
    }

    public function rebuildSection(EstimateGenerationSession $session, string $localEstimateKey): EstimateGenerationSession
    {
        $session = $this->generate($session);
        $draft = $session->draft_payload ?? [];

        foreach ($draft['local_estimates'] ?? [] as $index => $localEstimate) {
            if (($localEstimate['key'] ?? null) !== $localEstimateKey) {
                continue;
            }

            $draft['local_estimates'][$index]['assumptions'][] = 'Блок пересобран по запросу пользователя ' . now()->toDateTimeString();
        }

        $session->forceFill([
            'draft_payload' => $draft,
        ])->save();

        return $session->fresh(['documents']);
    }
}
