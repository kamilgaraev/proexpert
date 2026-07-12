<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final readonly class SketchClarificationData
{
    public function __construct(public array $answers)
    {
        $allowed = ['footprint_or_area', 'floor_count', 'floor_height', 'wall_material', 'foundation_type', 'roof_type', 'finish_level', 'region'];
        if (array_diff(array_keys($answers), $allowed) !== []) {
            throw new InvalidArgumentException('Sketch answer key is invalid.');
        }
        foreach ($answers as $key => $value) {
            $valid = match ($key) {
                'footprint_or_area', 'floor_height' => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0,
                'floor_count' => is_int($value) && $value >= 1 && $value <= 200,
                default => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 160,
            };
            if (! $valid) {
                throw new InvalidArgumentException('Sketch answer is invalid.');
            }
        }
    }
}
