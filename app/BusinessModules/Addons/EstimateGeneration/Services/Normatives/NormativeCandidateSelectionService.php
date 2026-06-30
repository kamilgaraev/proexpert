<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Illuminate\Validation\ValidationException;

use function trans_message;

class NormativeCandidateSelectionService
{
    public function __construct(
        protected EstimateNormativeMatcher $matcher,
        protected ResourceAssemblyService $resourceAssemblyService,
        protected EstimatePricingService $pricingService,
        protected EstimateValidationService $validationService,
        protected EstimateGenerationPackagePersistenceService $packagePersistenceService,
        protected EstimateGenerationLearningRecorder $learningRecorder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function select(EstimateGenerationSession $session, string $workItemKey, int $normId, bool $allowCatalogSelection = false): array
    {
        $draft = $session->draft_payload ?? [];
        $regionalContext = $draft['regional_context'] ?? $session->input_payload['regional_context'] ?? [];
        $found = false;
        $updated = false;
        $learningSelection = null;

        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $workIndex => $workItem) {
                    if ((string) ($workItem['key'] ?? '') !== $workItemKey) {
                        continue;
                    }

                    $found = true;
                    $this->assertWorkItemCanSelectNorm($workItem);
                    $this->assertCandidateWasOffered($workItem, $normId, $allowCatalogSelection);
                    $originalWorkItem = $workItem;

                    $context = [
                        'scope_type' => $localEstimate['scope_type'] ?? null,
                        'local_estimate_title' => $localEstimate['title'] ?? null,
                        'section_title' => $section['title'] ?? null,
                        'source_refs' => $section['source_refs'] ?? $localEstimate['source_refs'] ?? [],
                        'regional_context' => $regionalContext,
                        'selection_source' => $allowCatalogSelection ? 'catalog_search' : 'offered_candidate',
                    ];

                    $match = $this->matcher->matchSelectedNorm($normId, $workItem, $context);

                    if ($match === null) {
                        throw $this->validationException([
                            'norm_id' => [$this->message('estimate_generation.normative_candidate_not_available')],
                        ]);
                    }

                    $workItem = $this->resourceAssemblyService->applySelectedNormativeMatch($workItem, $match, $context);
                    $workItem = $this->pricingService->price([$workItem])[0];
                    $learningSelection = [$originalWorkItem, $workItem, $context];
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex] = $workItem;
                    $updated = true;

                    break 3;
                }
            }
        }

        if (!$found) {
            throw $this->validationException([
                'work_item_key' => [$this->message('estimate_generation.work_item_not_found')],
            ]);
        }

        if (!$updated) {
            throw $this->validationException([
                'norm_id' => [$this->message('estimate_generation.normative_candidate_not_available')],
            ]);
        }

        $draft = $this->validationService->validate($draft);
        if (!$this->packagePersistenceService->syncWorkItemPackageFromDraft($session, $draft, $workItemKey)) {
            $this->packagePersistenceService->syncFromDraft($session, $draft);
        }
        if ($learningSelection !== null) {
            [$originalWorkItem, $selectedWorkItem, $context] = $learningSelection;
            $this->learningRecorder->recordUserSelection($session, $originalWorkItem, $selectedWorkItem, $normId, $context);
            $this->learningRecorder->recordSupersededSelection($session, $originalWorkItem, $selectedWorkItem, $normId, $context);
        }
        $status = $this->draftRequiresReview($draft)
            ? 'review_required'
            : 'ready_for_review';

        $session->forceFill([
            'draft_payload' => $draft,
            'problem_flags' => $draft['problem_flags'] ?? [],
            'status' => $status,
            'processing_stage' => 'validation_and_normalization',
            'processing_progress' => 100,
            'last_error' => null,
        ])->save();

        return $draft;
    }

    /**
     * @param array<string, mixed> $workItem
     */
    protected function assertWorkItemCanSelectNorm(array $workItem): void
    {
        if ((string) ($workItem['item_type'] ?? 'priced_work') !== 'quantity_review') {
            return;
        }

        throw $this->validationException([
            'work_item_key' => [$this->message('estimate_generation.quantity_confirmation_required')],
        ]);
    }

    /**
     * @param array<string, mixed> $workItem
     */
    protected function assertCandidateWasOffered(array $workItem, int $normId, bool $allowCatalogSelection = false): void
    {
        if ($allowCatalogSelection) {
            return;
        }

        $currentMatch = $workItem['normative_match'] ?? null;
        if (
            is_array($currentMatch)
            && (int) ($currentMatch['norm_id'] ?? $currentMatch['id'] ?? 0) === $normId
        ) {
            return;
        }

        foreach ($workItem['normative_candidates'] ?? [] as $candidate) {
            if ((int) ($candidate['norm_id'] ?? 0) === $normId) {
                return;
            }
        }

        throw $this->validationException([
            'norm_id' => [$this->message('estimate_generation.normative_candidate_not_offered')],
        ]);
    }

    protected function message(string $key): string
    {
        return trans_message($key);
    }

    /**
     * @param array<string, array<int, string>> $messages
     */
    protected function validationException(array $messages): ValidationException
    {
        return ValidationException::withMessages($messages);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftRequiresReview(array $draft): bool
    {
        return (int) data_get($draft, 'quality_summary.normative_items.requires_review', 0) > 0
            || (int) data_get($draft, 'quality_summary.quantity_review_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.not_calculated_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.safe_norm_required_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.duplicate_work_items', 0) > 0;
    }
}
