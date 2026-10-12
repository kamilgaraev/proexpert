<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class CommercialPriceTargetRegion
{
    public function fromSession(EstimateGenerationSession $session): ?string
    {
        $input = $session->input_payload ?? [];
        $explicit = $this->normalize($input['region'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        $context = $input['regional_context'] ?? null;
        if (! is_array($context)) {
            return null;
        }
        foreach (['region_id', 'estimate_regional_price_version_id'] as $key) {
            $selected = $input[$key] ?? null;
            if ((is_int($selected) || (is_string($selected) && ctype_digit($selected))) && (int) $selected > 0
                && (int) ($context[$key] ?? 0) === (int) $selected) {
                return $this->normalize($context['region_name'] ?? null);
            }
        }

        // An inferred description or a single active normative dataset does not establish the object's region.
        return null;
    }

    public function normalize(mixed $region): ?string
    {
        if (! is_string($region) || trim($region) === '' || mb_strlen($region) > 240 || preg_match('/[\x00-\x1F\x7F]/u', $region) !== 0) {
            return null;
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($region));

        return $normalized === null ? null : mb_strtolower($normalized, 'UTF-8');
    }
}
