<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;

final class EstimateGenerationQualityReviewPolicy
{
    private const DOMAINS = ['classification', 'geometry', 'normative_matching'];

    /** @param array<string, array<string, mixed>> $signals */
    public function decide(
        EffectiveEstimateGenerationSettings $settings,
        array $signals,
    ): EstimateGenerationQualityReviewDecision {
        $reasons = [];

        foreach (self::DOMAINS as $domain) {
            $signal = is_array($signals[$domain] ?? null) ? $signals[$domain] : [];
            $hardBlockers = is_array($signal['hard_blockers'] ?? null) ? $signal['hard_blockers'] : [];
            foreach ($hardBlockers as $blocker) {
                if (is_string($blocker) && preg_match('/^[a-z][a-z0-9_]{1,80}$/D', $blocker) === 1) {
                    $reasons[] = $domain.'_'.$blocker;
                }
            }

            $confidence = $signal['confidence'] ?? null;
            $providerRequiresReview = ($signal['provider_requires_review'] ?? false) === true;
            if ($settings->requiresManualReview('low_confidence')
                && (($providerRequiresReview && ! is_int($confidence) && ! is_float($confidence))
                    || ((is_int($confidence) || is_float($confidence))
                        && is_finite((float) $confidence)
                        && (float) $confidence < $this->threshold($settings, $domain)))) {
                $reasons[] = $domain.'_low_confidence';
            }
        }

        $reasons = array_values(array_unique($reasons));

        return new EstimateGenerationQualityReviewDecision($reasons !== [], $reasons);
    }

    private function threshold(EffectiveEstimateGenerationSettings $settings, string $domain): float
    {
        return (float) match ($domain) {
            'classification' => $settings->confidence('classification'),
            'geometry' => $settings->confidence('geometry'),
            'normative_matching' => $settings->confidence('normative_matching'),
        };
    }
}
