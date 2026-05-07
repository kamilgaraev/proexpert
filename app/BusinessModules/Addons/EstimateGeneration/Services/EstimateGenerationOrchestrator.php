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
                $workItems = $this->resourceAssemblyService->enrich($workItems, [
                    'scope_type' => $localEstimate['scope_type'] ?? null,
                    'local_estimate_title' => $localEstimate['title'] ?? null,
                    'section_title' => $section['title'] ?? null,
                    'source_refs' => $section['source_refs'] ?? $localEstimate['source_refs'] ?? [],
                ]);
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
        $draft['normative_matching'] = $this->normativeMatchingSummary($localEstimates);

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

    private function normativeMatchingSummary(array $localEstimates): array
    {
        $matched = 0;
        $unmatched = 0;
        $lowConfidence = 0;
        $sourceType = null;
        $versionKey = null;

        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    $match = $workItem['normative_match'] ?? [];

                    if (($match['status'] ?? null) === 'matched') {
                        $matched++;
                        $sourceType = $match['dataset_version']['source_type'] ?? $sourceType;
                        $versionKey = $match['dataset_version']['version_key'] ?? $versionKey;

                        if ((float) ($match['confidence'] ?? 0) < 0.55) {
                            $lowConfidence++;
                        }

                        continue;
                    }

                    $unmatched++;
                }
            }
        }

        return [
            'enabled' => true,
            'source_type' => $sourceType,
            'version_key' => $versionKey,
            'matched_work_items' => $matched,
            'unmatched_work_items' => $unmatched,
            'low_confidence_work_items' => $lowConfidence,
        ];
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
