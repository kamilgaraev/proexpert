<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityReviewPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationQualityReviewPolicyTest extends TestCase
{
    #[Test]
    public function enabled_low_confidence_review_uses_each_domain_threshold(): void
    {
        $decision = (new EstimateGenerationQualityReviewPolicy)->decide($this->settings(true), [
            'classification' => ['confidence' => 0.69],
            'geometry' => ['confidence' => 0.79],
            'normative_matching' => ['confidence' => 0.89],
        ]);

        self::assertTrue($decision->requiresReview);
        self::assertSame([
            'classification_low_confidence',
            'geometry_low_confidence',
            'normative_matching_low_confidence',
        ], $decision->reasons);
    }

    #[Test]
    public function disabled_low_confidence_review_does_not_create_decorative_review_reasons(): void
    {
        $decision = (new EstimateGenerationQualityReviewPolicy)->decide($this->settings(false), [
            'classification' => ['confidence' => 0.1],
            'geometry' => ['confidence' => 0.1],
            'normative_matching' => ['confidence' => 0.1],
        ]);

        self::assertFalse($decision->requiresReview);
        self::assertSame([], $decision->reasons);
    }

    #[Test]
    public function hard_geometry_invalidity_is_unconditional(): void
    {
        $decision = (new EstimateGenerationQualityReviewPolicy)->decide($this->settings(false), [
            'geometry' => ['confidence' => 0.99, 'hard_blockers' => ['scale_conflict']],
        ]);

        self::assertTrue($decision->requiresReview);
        self::assertSame(['geometry_scale_conflict'], $decision->reasons);
    }

    #[Test]
    public function provider_classification_review_metadata_survives_missing_numeric_confidence(): void
    {
        $decision = (new EstimateGenerationQualityReviewPolicy)->decide($this->settings(true), [
            'classification' => ['confidence' => null, 'provider_requires_review' => true],
        ]);

        self::assertTrue($decision->requiresReview);
        self::assertSame(['classification_low_confidence'], $decision->reasons);
    }

    private function settings(bool $manualReview): EffectiveEstimateGenerationSettings
    {
        $snapshot = [
            'schema_version' => 2,
            'models' => ['vision' => 'provider/vision-v2', 'classification' => 'provider/classification-v2', 'normative_matching' => 'provider/normative-v2'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 800],
            'timeouts' => ['vision' => 81, 'classification' => 82, 'normative_matching' => 83],
            'retries' => ['vision' => 0, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.8000', 'normative_matching' => '0.9000'],
            'enabled_formats' => ['pdf', 'png'],
            'manual_review' => ['low_confidence' => $manualReview],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ];

        return EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 41,
            'scope' => 'organization',
            'organization_id' => 17,
            'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
            'snapshot' => $snapshot,
        ], 17);
    }
}
