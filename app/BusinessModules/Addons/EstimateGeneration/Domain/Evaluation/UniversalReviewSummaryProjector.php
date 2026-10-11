<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;

final readonly class UniversalReviewSummaryProjector
{
    public function __construct(private EstimateGenerationReviewItemService $reviews = new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter)) {}

    public function project(array $draft): array
    {
        $positions = array_column($draft['evaluation_result']['scenarios'][0]['positions'] ?? [], null, 'key');
        foreach ($draft['local_estimates'] ?? [] as $estimateIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    $position = $positions[$item['key']] ?? null;
                    if ($position === null) {
                        continue;
                    }
                    foreach (['quantity', 'unit_price', 'total_cost', 'pricing_status'] as $field) {
                        $draft['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex][$field] = $position[$field];
                    }
                }
            }
        }
        $draft['quality_summary'] = ['level' => 'review_required', 'accuracy_calibrated' => false,
            'review_queue_items' => $this->reviews->projectionForDraft($draft),
            'content_version' => ReviewSummarySnapshot::contentVersion($draft),
            'review_items' => ReviewSummarySnapshot::create($draft, $this->reviews->summaryForDraft($draft))];

        return $draft;
    }
}
