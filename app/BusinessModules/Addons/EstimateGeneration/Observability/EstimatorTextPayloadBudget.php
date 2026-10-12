<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\Support\AI\TokenBudgetService;
use DomainException;

/** Estimator-only protection: the general assistant's policy is unchanged. */
final class EstimatorTextPayloadBudget
{
    public static function guard(string $content): void
    {
        // Extremely long BPE pieces can consume quadratic CPU before admission.
        // Reject such a source explicitly; never shorten its business contents.
        if (preg_match('/[\p{L}\p{N}\p{M}_+\/=-]{2049}|\s{2049}/u', $content) !== 0) {
            throw new DomainException('estimate_text_segment_requires_source_split');
        }
    }

    public function fits(string $content): bool
    {
        // Large common manifests must be partitioned before expensive tokenization.
        if (strlen($content) > 196608) {
            return false;
        }
        try {
            self::guard($content);
            (new TokenBudgetService)->prepare([
                // Headroom exceeds the current server role prompts. The real
                // adapter still checks its exact protected messages before wire.
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => $content],
            ], [], 'json');

            return true;
        } catch (DomainException $exception) {
            if (in_array($exception->getMessage(), ['ai_token_budget_exhausted', 'estimate_text_segment_requires_source_split'], true)) {
                return false;
            }

            throw $exception;
        }
    }
}
