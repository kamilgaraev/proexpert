<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

/** Logical stage claims share one finite budget across queue delivery, recovery and input revisions. */
final class PipelineRetryBudget
{
    public const MAX_ATTEMPTS = 3;

    public static function exhausted(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS;
    }
}
