<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use Illuminate\Support\Collection;
use Throwable;

class EstimatorReadinessService
{
    public function __construct(
        private readonly ?EstimateGenerationReviewItemService $reviewItemService = null,
        private readonly ?EstimatorReadinessEvaluator $evaluator = null,
        private readonly ?DocumentReadinessClassifier $documentClassifier = null,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(EstimateGenerationSession $session): array
    {
        $documents = $this->documents($session);
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $quality = is_array($draft['quality_summary'] ?? null) ? $draft['quality_summary'] : [];
        $status = $session->status instanceof EstimateGenerationStatus
            ? $session->status->value
            : (string) $session->status;

        return ($this->evaluator ?? new EstimatorReadinessEvaluator)->evaluate(new EstimatorReadinessInput(
            sessionStatus: $status,
            hasDraft: is_array($session->draft_payload) && ($session->draft_payload['local_estimates'] ?? []) !== [],
            qualityStatus: (string) ($quality['status'] ?? ''),
            qualityLevel: (string) ($quality['level'] ?? ''),
            metrics: $this->metrics($session, $documents, $draft, $quality),
        ));
    }

    /** @return Collection<int, EstimateGenerationDocument> */
    private function documents(EstimateGenerationSession $session): Collection
    {
        return $session->relationLoaded('documents') ? $session->documents : collect();
    }

    /**
     * @param  Collection<int, EstimateGenerationDocument>  $documents
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $quality
     * @return array<string, int>
     */
    private function metrics(EstimateGenerationSession $session, Collection $documents, array $draft, array $quality): array
    {
        $totalWorkItems = (int) ($quality['total_work_items'] ?? 0);
        $operationWorkItems = (int) ($quality['operation_work_items'] ?? 0);
        $quantityReviewWorkItems = (int) ($quality['quantity_review_work_items'] ?? 0);
        $normativeItems = is_array($quality['normative_items'] ?? null) ? $quality['normative_items'] : [];
        $hasPersistedReviewSummary = is_array($quality['review_items'] ?? null);
        $reviewSummaryFresh = $hasPersistedReviewSummary && ReviewSummarySnapshot::isFresh($draft, $quality['review_items']);
        $reviewSummary = $reviewSummaryFresh
            ? $quality['review_items']
            : ($session->exists ? [] : $this->reviewSummary($session));
        $zeroTotalCalculatedItems = $this->zeroTotalCalculatedPricedWorkItems($draft);

        return [
            'documents_total' => $documents->count(),
            'documents_ready' => $documents->where('status', 'ready')->count(),
            'documents_pending' => $documents->filter(
                static fn (EstimateGenerationDocument $document): bool => in_array((string) $document->status, ['uploaded', 'queued', 'processing'], true),
            )->count(),
            'documents_action_required' => $documents->filter(
                fn (EstimateGenerationDocument $document): bool => ($this->documentClassifier ?? new DocumentReadinessClassifier)->requiresAction($document),
            )->count(),
            'facts' => $this->sumDocumentCount($documents, 'facts_count', 'facts'),
            'drawing_elements' => $this->sumDocumentCount($documents, 'drawing_elements_count', 'drawingElements'),
            'quantity_takeoffs' => $this->sumDocumentCount($documents, 'quantity_takeoffs_count', 'quantityTakeoffs'),
            'scope_inferences' => $this->sumDocumentCount($documents, 'scope_inferences_count', 'scopeInferences'),
            'priced_work_items' => max((int) ($quality['priced_work_items'] ?? 0) - $zeroTotalCalculatedItems, 0),
            'priced_work_items_total' => max($totalWorkItems - $operationWorkItems - $quantityReviewWorkItems, 0),
            'operation_work_items' => $operationWorkItems,
            'quantity_review_work_items' => $quantityReviewWorkItems,
            'not_calculated_work_items' => (int) ($quality['not_calculated_work_items'] ?? 0) + $zeroTotalCalculatedItems,
            'zero_total_calculated_work_items' => $zeroTotalCalculatedItems,
            'safe_norm_required_work_items' => (int) ($quality['safe_norm_required_work_items'] ?? 0),
            'duplicate_work_items' => (int) ($quality['duplicate_work_items'] ?? 0),
            'normative_requires_review' => (int) ($normativeItems['requires_review'] ?? $quality['safe_norm_required_work_items'] ?? 0),
            'review_items_total' => (int) ($reviewSummary['total'] ?? 0),
            'review_items_blocking' => (int) ($reviewSummary['blocking'] ?? 0),
            'review_items_warning' => (int) ($reviewSummary['warning'] ?? 0),
            'review_items_optional' => (int) ($reviewSummary['optional'] ?? 0),
            'review_summary_stale' => $session->exists && is_array($session->draft_payload) && ($session->draft_payload['local_estimates'] ?? []) !== [] && ! $reviewSummaryFresh ? 1 : 0,
            'problem_flags' => count(is_array($draft['problem_flags'] ?? null) ? $draft['problem_flags'] : ($session->problem_flags ?? [])),
        ];
    }

    /** @return array<string, int> */
    private function reviewSummary(EstimateGenerationSession $session): array
    {
        try {
            $service = $this->reviewItemService ?? new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter);
            $queue = $service->forSession($session);

            return is_array($queue['summary'] ?? null) ? $queue['summary'] : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $draft */
    private function zeroTotalCalculatedPricedWorkItems(array $draft): int
    {
        $count = 0;
        foreach (($draft['local_estimates'] ?? []) as $localEstimate) {
            foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $workItem) {
                    if (
                        is_array($workItem)
                        && ($workItem['item_type'] ?? null) === 'priced_work'
                        && ($workItem['pricing_status'] ?? null) === 'calculated'
                        && (float) ($workItem['total_cost'] ?? 0) <= 0
                    ) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /** @param Collection<int, EstimateGenerationDocument> $documents */
    private function sumDocumentCount(Collection $documents, string $attribute, string $relation): int
    {
        return $documents->sum(function (EstimateGenerationDocument $document) use ($attribute, $relation): int {
            if ($document->relationLoaded($relation)) {
                return $document->{$relation}->count();
            }

            return is_numeric($document->getAttribute($attribute)) ? (int) $document->getAttribute($attribute) : 0;
        });
    }
}
