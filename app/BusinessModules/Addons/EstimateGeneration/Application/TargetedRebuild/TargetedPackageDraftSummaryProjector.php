<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;

final readonly class TargetedPackageDraftSummaryProjector
{
    public function __construct(
        private EstimateGenerationReviewItemService $reviewItems = new EstimateGenerationReviewItemService(
            new EstimateGenerationPackagePresenter,
        ),
        private DraftReadinessProjector $readiness = new DraftReadinessProjector,
    ) {}

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    public function project(array $draft): array
    {
        $draft = $this->refreshTotals($draft);
        $draft['quality_summary'] = [
            ...(is_array($draft['quality_summary'] ?? null) ? $draft['quality_summary'] : []),
            'review_queue_items' => $this->reviewItems->projectionForDraft($draft),
        ];
        $draft['quality_summary']['content_version'] = ReviewSummarySnapshot::contentVersion($draft);
        $draft['quality_summary']['review_items'] = ReviewSummarySnapshot::create(
            $draft,
            $this->reviewItems->summaryForDraft($draft),
        );

        return $this->readiness->project($draft);
    }

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    private function refreshTotals(array $draft): array
    {
        $baseTotal = 0.0;
        $workItemsCount = 0;
        $localEstimates = is_array($draft['local_estimates'] ?? null) ? $draft['local_estimates'] : [];

        foreach ($localEstimates as $estimateIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }
            $localTotal = 0.0;
            $sections = is_array($localEstimate['sections'] ?? null) ? $localEstimate['sections'] : [];
            foreach ($sections as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }
                $sectionTotal = 0.0;
                $items = is_array($section['work_items'] ?? null) ? $section['work_items'] : [];
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $workItemsCount++;
                    if (is_numeric($item['total_cost'] ?? null)) {
                        $sectionTotal += (float) $item['total_cost'];
                    }
                }
                $draft['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['section_totals'] = [
                    'total_cost' => round($sectionTotal, 2),
                    'items_count' => count($items),
                ];
                $localTotal += $sectionTotal;
            }
            $draft['local_estimates'][$estimateIndex]['totals'] = [
                'total_cost' => round($localTotal, 2),
                'sections_count' => count($sections),
            ];
            $baseTotal += $localTotal;
        }

        $contingencyPercent = is_numeric($draft['contingency_percent'] ?? null)
            ? max((float) $draft['contingency_percent'], 0.0)
            : 0.0;
        $contingencyAmount = round($baseTotal * $contingencyPercent / 100, 2);
        $draft['totals'] = [
            'total_cost' => round($baseTotal + $contingencyAmount, 2),
            'base_total_cost' => round($baseTotal, 2),
            'contingency' => [
                'percent' => $contingencyPercent,
                'amount' => $contingencyAmount,
            ],
            'local_estimates_count' => count($localEstimates),
            'work_items_count' => $workItemsCount,
        ];

        return $draft;
    }
}
