<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Closure;

final class DraftPublicationGate
{
    /** @param array<string, mixed> $draft */
    public function allows(array $draft, bool $requiresReview): bool
    {
        if ($requiresReview) {
            return false;
        }
        if (($draft['generation_contract'] ?? null) !== 'most_ordinary_estimate:v1') {
            return true;
        }

        return ($draft['stage6_status'] ?? null) === 'ready'
            && ($draft['is_complete'] ?? null) === true
            && ($draft['stage6_review_items'] ?? null) === [];
    }

    /** @param array<string, mixed> $draft */
    public function persistWhenAllowed(array $draft, bool $requiresReview, Closure $persist): bool
    {
        if (! $this->allows($draft, $requiresReview)) {
            return false;
        }
        $persist();

        return true;
    }
}
