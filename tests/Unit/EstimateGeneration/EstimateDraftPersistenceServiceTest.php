<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use PHPUnit\Framework\TestCase;

final class EstimateDraftPersistenceServiceTest extends TestCase
{
    public function test_review_required_quality_status_blocks_apply_guard(): void
    {
        $blocker = (new TestableEstimateDraftPersistenceService())->blockerFor([
            'quality_summary' => [
                'status' => 'review_required',
                'not_calculated_work_items' => 0,
                'safe_norm_required_work_items' => 0,
                'normative_items' => [
                    'requires_review' => 0,
                    'review_priced' => 1,
                ],
            ],
        ]);

        self::assertSame(['type' => 'prices_require_review'], $blocker);
    }
}

final class TestableEstimateDraftPersistenceService extends EstimateDraftPersistenceService
{
    /**
     * @param array<string, mixed> $draft
     */
    public function blockerFor(array $draft): ?array
    {
        return $this->applyBlocker($draft);
    }
}
