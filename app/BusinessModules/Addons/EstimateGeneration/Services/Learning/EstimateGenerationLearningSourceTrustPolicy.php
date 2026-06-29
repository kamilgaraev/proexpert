<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;

final class EstimateGenerationLearningSourceTrustPolicy
{
    private const TRUSTED_SOURCE_TYPES = [
        'imported_estimate',
        'golden_estimate_upload',
        'superadmin_training_dataset',
        'manual_review_choice',
        'manual_review_rejection',
        'user_selection',
        'user_rejection',
    ];

    private const BLOCKING_QUALITY_FLAGS = [
        'do_not_index',
        'unindexable',
        'low_quality',
    ];

    public static function isIndexable(EstimateGenerationLearningExample $example): bool
    {
        $flags = array_map('strval', is_array($example->quality_flags) ? $example->quality_flags : []);

        return self::isTrustedSourceType((string) $example->source_type)
            && count(array_intersect($flags, self::BLOCKING_QUALITY_FLAGS)) === 0;
    }

    public static function isTrustedSourceType(string $sourceType): bool
    {
        return in_array($sourceType, self::TRUSTED_SOURCE_TYPES, true);
    }

    /**
     * @return array<int, string>
     */
    public static function trustedSourceTypes(): array
    {
        return self::TRUSTED_SOURCE_TYPES;
    }
}
