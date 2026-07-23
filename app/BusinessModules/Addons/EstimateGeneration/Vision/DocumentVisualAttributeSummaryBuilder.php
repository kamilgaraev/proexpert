<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

final class DocumentVisualAttributeSummaryBuilder
{
    public function summarize(array $pagePayloads): array
    {
        $types = [];

        foreach ($pagePayloads as $payload) {
            $vision = is_array($payload) && is_array($payload['vision_analysis'] ?? null)
                ? $payload['vision_analysis']
                : [];
            if (! in_array($vision['sheet_type'] ?? null, ['elevation', 'section', 'photo'], true)) {
                continue;
            }

            $roofType = is_array($vision['visual_attributes']['roof_type'] ?? null)
                ? $vision['visual_attributes']['roof_type']
                : [];
            $confidence = $roofType['confidence'] ?? null;
            if ((! is_float($confidence) && ! is_int($confidence)) || $confidence < 0.75) {
                continue;
            }

            $normalized = match ($roofType['value'] ?? null) {
                'pitched', 'gable', 'hip' => 'pitched',
                'flat' => 'flat',
                default => null,
            };
            if ($normalized !== null) {
                $types[$normalized] = true;
            }
        }

        return count($types) === 1 ? ['roof_type' => array_key_first($types)] : [];
    }
}
