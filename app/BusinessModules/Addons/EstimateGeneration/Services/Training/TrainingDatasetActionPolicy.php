<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

final class TrainingDatasetActionPolicy
{
    public static function allows(string $kind, string $status, string $trustedStatus, string $action): bool
    {
        if (! in_array($kind, ['development', 'regression', 'acceptance'], true)
            || ! in_array($status, ['draft', 'processing', 'review_required', 'approved', 'rejected', 'archived'], true)
            || ! in_array($trustedStatus, ['draft', 'pending', 'approved', 'rejected'], true)) {
            return false;
        }
        if ($action === 'process') {
            return $status === 'draft';
        }
        if ($kind === 'development') {
            return match ($action) {
                'submit_review' => $status === 'review_required' && $trustedStatus === 'draft',
                'approve_review', 'reject_review' => $status === 'review_required' && $trustedStatus === 'pending',
                'approve_primary' => $status === 'review_required' && $trustedStatus === 'approved',
                'train', 'tune' => $status === 'approved' && $trustedStatus === 'approved',
                default => false,
            };
        }

        return $action === 'approve_primary' && $status === 'review_required';
    }
}
