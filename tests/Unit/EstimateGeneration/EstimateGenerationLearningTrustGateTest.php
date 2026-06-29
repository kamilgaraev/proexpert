<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningSourceTrustPolicy;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationLearningTrustGateTest extends TestCase
{
    public function test_generated_learning_examples_are_not_indexable_for_scoring_or_rag(): void
    {
        $example = new EstimateGenerationLearningExample([
            'source_type' => 'ai_generated_estimate',
            'quality_flags' => ['unit_compatible'],
        ]);

        $this->assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($example));
    }

    public function test_manual_and_imported_learning_examples_remain_indexable(): void
    {
        foreach (['imported_estimate', 'golden_estimate_upload', 'superadmin_training_dataset', 'manual_review_choice', 'user_selection'] as $sourceType) {
            $example = new EstimateGenerationLearningExample([
                'source_type' => $sourceType,
                'quality_flags' => ['unit_compatible'],
            ]);

            $this->assertTrue(EstimateGenerationLearningSourceTrustPolicy::isIndexable($example), $sourceType);
        }
    }

    public function test_low_quality_learning_examples_remain_blocked_even_from_trusted_sources(): void
    {
        $example = new EstimateGenerationLearningExample([
            'source_type' => 'manual_review_choice',
            'quality_flags' => ['low_quality'],
        ]);

        $this->assertFalse(EstimateGenerationLearningSourceTrustPolicy::isIndexable($example));
    }
}
