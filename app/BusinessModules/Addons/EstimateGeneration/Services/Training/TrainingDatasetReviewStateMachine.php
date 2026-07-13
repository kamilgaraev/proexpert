<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

use DomainException;

final class TrainingDatasetReviewStateMachine
{
    public static function submit(string $current, int $submitterId, ?int $reviewerId): string
    {
        if ($current !== 'draft' || $submitterId <= 0 || $reviewerId !== null) {
            throw new DomainException('training_dataset_review_transition_invalid');
        }

        return 'pending';
    }

    public static function approve(string $current, int $submitterId, int $reviewerId): string
    {
        self::assertTrustedDecision($current, $submitterId, $reviewerId);

        return 'approved';
    }

    public static function reject(string $current, int $submitterId, int $reviewerId): string
    {
        self::assertTrustedDecision($current, $submitterId, $reviewerId);

        return 'rejected';
    }

    private static function assertTrustedDecision(string $current, int $submitterId, int $reviewerId): void
    {
        if ($submitterId === $reviewerId) {
            throw new DomainException('training_dataset_self_review_forbidden');
        }
        if ($current !== 'pending' || $submitterId <= 0 || $reviewerId <= 0) {
            throw new DomainException('training_dataset_review_transition_invalid');
        }
    }
}
