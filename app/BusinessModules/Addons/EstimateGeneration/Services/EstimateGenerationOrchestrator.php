<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityGateService;
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
        protected PackagePlannerService $packagePlannerService,
        protected EstimateGenerationPackagePersistenceService $packagePersistenceService,
        protected EstimateGenerationQualityGateService $qualityGateService,
    ) {}

    public function analyze(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $documents = $session->documents()
            ->with('facts')
            ->get()
            ->map(static fn ($document): array => [
                'id' => $document->id,
                'filename' => $document->filename,
                'status' => $document->status,
                'extracted_text' => $document->extracted_text,
                'structured_payload' => $document->structured_payload ?? [],
                'facts_summary' => $document->facts_summary ?? [],
                'quality' => [
                    'score' => $document->quality_score,
                    'level' => $document->quality_level,
                    'flags' => $document->quality_flags ?? [],
                ],
                'facts' => $document->facts->map(static fn ($fact): array => $fact->toArray())->all(),
            ])
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
        $objectProfile = $this->packagePlannerService->profileFromAnalysis($analysis);
        $packagePlan = $this->packagePlannerService->plan($objectProfile);
        $localEstimates = $this->decompositionService->decomposePackagePlan($analysis, $packagePlan);
        $regionalContext = $session->input_payload['regional_context'] ?? $analysis['regional_context'] ?? [];
        $localEstimatesCount = max(count($localEstimates), 1);

        foreach ($localEstimates as $localIndex => $localEstimate) {
            $packageProgressStart = 45 + (int) floor(($localIndex / $localEstimatesCount) * 45);
            $packageProgressEnd = 45 + (int) floor((($localIndex + 1) / $localEstimatesCount) * 45);
            $this->updateGenerationProgress($session, 'resource_enrichment', $packageProgressStart);

            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $workItems = $this->workItemGenerationService->build($localEstimate, $analysis);
                $progressCallback = function (int $processed, int $total) use ($session, $packageProgressStart, $packageProgressEnd): void {
                    $range = max($packageProgressEnd - $packageProgressStart, 1);
                    $progress = $packageProgressStart + (int) floor(($processed / max($total, 1)) * $range);
                    $this->updateGenerationProgress($session, 'resource_enrichment', min($progress, $packageProgressEnd));
                };
                $workItems = $this->resourceAssemblyService->enrich($workItems, [
                    'organization_id' => $session->organization_id,
                    'project_id' => $session->project_id,
                    'user_id' => $session->user_id,
                    'scope_type' => $localEstimate['scope_type'] ?? null,
                    'local_estimate_title' => $localEstimate['title'] ?? null,
                    'section_title' => $section['title'] ?? null,
                    'source_refs' => $section['source_refs'] ?? $localEstimate['source_refs'] ?? [],
                    'regional_context' => $regionalContext,
                    'progress_callback' => $progressCallback,
                ]);
                $workItems = $this->pricingService->price($workItems);
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $workItems;
            }
        }

        $this->updateGenerationProgress($session, 'validation_and_normalization', 92);

        $draft = [
            'title' => $session->input_payload['description'] ?? 'AI draft estimate',
            'object_profile' => $objectProfile->toArray(),
            'package_plan' => $packagePlan->toArray(),
            'source_documents' => Arr::get($analysis, 'source_documents', []),
            'local_estimates' => $localEstimates,
            'traceability' => [
                'analysis' => Arr::get($analysis, 'detected_structure', []),
                'document_context' => Arr::get($analysis, 'document_context', []),
                'document_source_refs' => Arr::get($analysis, 'document_context.source_refs', []),
            ],
            'regional_context' => $regionalContext,
            'contingency_percent' => Arr::get($analysis, 'object.contingency_percent'),
            'problem_flags' => Arr::get($analysis, 'problem_flags', []),
        ];
        $draft['normative_matching'] = $this->normativeMatchingSummary($localEstimates);

        $draft = $this->validationService->validate($draft);
        $qualityReport = $this->qualityGateService->evaluate($draft);
        $draft['quality_summary'] = [
            ...($draft['quality_summary'] ?? []),
            ...$qualityReport->toArray(),
        ];
        $draft['problem_flags'] = array_values(array_unique([
            ...($draft['problem_flags'] ?? []),
            ...$qualityReport->criticalFlags,
            ...$qualityReport->warningFlags,
        ]));
        $this->packagePersistenceService->syncFromDraft($session, $draft);

        $status = match ($qualityReport->level) {
            'passed' => 'ready_for_review',
            'blocked' => 'blocked',
            default => 'review_required',
        };

        $session->forceFill([
            'status' => $status,
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'draft_payload' => $draft,
            'problem_flags' => $draft['problem_flags'] ?? [],
            'last_error' => null,
        ])->save();

        return $session->fresh(['documents']);
    }

    private function updateGenerationProgress(EstimateGenerationSession $session, string $stage, int $progress): void
    {
        $session->forceFill([
            'status' => 'processing',
            'processing_stage' => $stage,
            'processing_progress' => max(0, min($progress, 99)),
        ])->save();
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
