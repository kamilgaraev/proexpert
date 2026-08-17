<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessInspector;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class EstimateDraftPersistenceService
{
    public function __construct(
        private EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard,
        private EstimateGenerationReviewItemService $reviewItemService,
        private ?DraftReadinessInspector $readinessInspector = null,
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
    public function persistableDraftTotal(array $draft): float|string
    {
        $exact = ($draft['generation_contract'] ?? null) === 'most_ordinary_estimate:v1';
        if (! $exact) {
            $total = 0.0;

            foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
                if (is_array($localEstimate)) {
                    $total += $this->persistableLocalEstimateTotal($localEstimate);
                }
            }

            return round($total, 2);
        }

        $total = BigDecimal::zero();

        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (is_array($localEstimate)) {
                $total = $total->plus($this->persistableLocalEstimateTotal($localEstimate, true));
            }
        }

        return $total->strippedOfTrailingZeros()->__toString();
    }

    /** @param array<string, mixed> $localEstimate */
    public function persistableLocalEstimateTotal(array $localEstimate, bool $exact = false): float|string
    {
        if (! $exact) {
            $total = 0.0;

            foreach ($localEstimate['sections'] ?? [] as $section) {
                if (is_array($section)) {
                    $total += $this->workItemsTotal($this->persistableWorkItems($section['work_items'] ?? []));
                }
            }

            return round($total, 2);
        }

        $total = BigDecimal::zero();

        foreach ($localEstimate['sections'] ?? [] as $section) {
            if (is_array($section)) {
                $total = $total->plus($this->workItemsTotal(
                    $this->persistableWorkItems($section['work_items'] ?? []),
                    true,
                ));
            }
        }

        return $total->strippedOfTrailingZeros()->__toString();
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
    public function workItemsTotal(array $workItems, bool $exact = false): float|string
    {
        if (! $exact) {
            return round(array_sum(array_map(
                static fn (array $workItem): float => (float) ($workItem['total_cost'] ?? 0),
                $workItems,
            )), 2);
        }

        $total = BigDecimal::zero();
        foreach ($workItems as $workItem) {
            $value = $workItem['total_cost'] ?? '0';
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            try {
                $total = $total->plus((string) $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return $total->strippedOfTrailingZeros()->__toString();
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
        if (($draft['generation_contract'] ?? null) === 'most_ordinary_estimate:v1'
            && (($draft['stage6_status'] ?? null) !== 'ready' || ($draft['is_complete'] ?? null) !== true)) {
            return ['type' => 'incomplete_stage6_draft'];
        }

        if (($draft['generation_contract'] ?? null) === 'most_ordinary_estimate:v1') {
            $inspection = ($this->readinessInspector ?? new DraftReadinessInspector)->inspect($draft);
            if ($inspection->blockingIssues !== []) {
                return ['type' => 'blocked', 'code' => $inspection->blockingIssues[0]['code']];
            }
        }

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
            || BigDecimal::of($this->persistableDraftTotal($draft))->isLessThanOrEqualTo(BigDecimal::zero())
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
