<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class EstimateDraftPersistenceService
{
    public function __construct(
        private EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard,
        private EstimateGenerationReviewItemService $reviewItemService,
    ) {}

    /** @return array<string, mixed> */
    public function validatedDraft(EstimateGenerationSession $session): array
    {
        $draft = $session->draft_payload ?? [];

        if (($draft['local_estimates'] ?? []) === []) {
            throw new \RuntimeException('Draft is empty.');
        }

        $this->assertNoBlockingReviewItems($session);
        $this->assertDraftCanBeApplied($draft);

        return $draft;
    }

    /** @param array<string, mixed> $workItem */
    public function isPersistableWorkItem(array $workItem): bool
    {
        return $this->finalWorkItemGuard->isFinalEstimateWorkItem($workItem);
    }

    /** @param array<string, mixed> $workItem */
    public function normativeRateCode(array $workItem): ?string
    {
        return $this->finalWorkItemGuard->normativeRateCode($workItem);
    }

    /** @param array<string, mixed> $draft */
    public function persistableDraftTotal(array $draft): float
    {
        $total = 0.0;

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (is_array($localEstimate)) {
                $total += $this->persistableLocalEstimateTotal($localEstimate);
            }
        }

        return round($total, 2);
    }

    /** @param array<string, mixed> $localEstimate */
    public function persistableLocalEstimateTotal(array $localEstimate): float
    {
        $total = 0.0;

        foreach ($localEstimate['sections'] ?? [] as $section) {
            if (is_array($section)) {
                $total += $this->workItemsTotal($this->persistableWorkItems($section['work_items'] ?? []));
            }
        }

        return round($total, 2);
    }

    /**
     * @param  array<int, mixed>  $workItems
     * @return array<int, array<string, mixed>>
     */
    public function persistableWorkItems(array $workItems): array
    {
        return array_values(array_filter(
            $workItems,
            fn (mixed $workItem): bool => is_array($workItem) && $this->isPersistableWorkItem($workItem),
        ));
    }

    /** @param array<int, array<string, mixed>> $workItems */
    public function workItemsTotal(array $workItems): float
    {
        return round(array_sum(array_map(
            static fn (array $workItem): float => (float) ($workItem['total_cost'] ?? 0),
            $workItems,
        )), 2);
    }

    private function assertNoBlockingReviewItems(EstimateGenerationSession $session): void
    {
        $reviewQueue = $this->reviewItemService->forSession($session);
        $blockingCount = (int) data_get($reviewQueue, 'summary.blocking', 0);

        if ($blockingCount > 0) {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.apply_review_items_blocked', [
                    'count' => $blockingCount,
                ])],
            ]);
        }
    }

    /** @param array<string, mixed> $draft */
    private function assertDraftCanBeApplied(array $draft): void
    {
        $blocker = $this->findApplyBlocker($draft);

        if ($blocker === null) {
            return;
        }

        if ($blocker['type'] === 'unresolved_normatives') {
            throw ValidationException::withMessages([
                'draft' => [trans_message('estimate_generation.unresolved_normatives', ['count' => $blocker['count']])],
            ]);
        }

        $translationKey = match ($blocker['type']) {
            'quantities_require_review' => 'estimate_generation.apply_quantities_require_review',
            'blocked' => 'estimate_generation.apply_blocked',
            default => 'estimate_generation.apply_prices_require_review',
        };

        throw ValidationException::withMessages(['draft' => [trans_message($translationKey)]]);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{type: string, count?: int}|null
     */
    public function findApplyBlocker(array $draft): ?array
    {
        $qualityStatus = (string) ($draft['quality_summary']['status'] ?? '');
        $qualityLevel = (string) ($draft['quality_summary']['level'] ?? '');
        $unresolvedNormatives = (int) data_get($draft, 'quality_summary.normative_items.requires_review', 0);
        $quantityReviewWorkItems = (int) data_get($draft, 'quality_summary.quantity_review_work_items', 0);

        if ($unresolvedNormatives > 0) {
            return ['type' => 'unresolved_normatives', 'count' => $unresolvedNormatives];
        }

        if ($quantityReviewWorkItems > 0) {
            return ['type' => 'quantities_require_review', 'count' => $quantityReviewWorkItems];
        }

        if (
            (int) data_get($draft, 'quality_summary.not_calculated_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.safe_norm_required_work_items', 0) > 0
            || (int) data_get($draft, 'quality_summary.duplicate_work_items', 0) > 0
            || $qualityStatus === 'review_required'
            || $this->persistableDraftTotal($draft) <= 0
            || $this->hasNonPersistablePricedWorkItems($draft)
        ) {
            return ['type' => 'prices_require_review'];
        }

        if ($qualityStatus === 'critical' || $qualityLevel === 'blocked') {
            return ['type' => 'blocked'];
        }

        return null;
    }

    /** @param array<string, mixed> $draft */
    private function hasNonPersistablePricedWorkItems(array $draft): bool
    {
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $workItem) {
                    if (! is_array($workItem)) {
                        continue;
                    }

                    $type = (string) ($workItem['item_type'] ?? 'priced_work');
                    if (! in_array($type, ['operation', 'resource_note', 'review_note'], true)
                        && ! $this->isPersistableWorkItem($workItem)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
