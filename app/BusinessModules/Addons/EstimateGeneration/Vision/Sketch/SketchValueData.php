<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final readonly class SketchValueData
{
    private const DOMAINS = [
        'wall_material' => ['brick', 'concrete', 'aerated_concrete', 'wood', 'frame'],
        'foundation_type' => ['strip', 'slab', 'pile', 'column'],
        'roof_type' => ['flat', 'pitched', 'gable', 'hip'],
        'finish_level' => ['shell', 'rough', 'pre_finish', 'finished'],
    ];

    public function __construct(public string $key, public int|float|string|array $value)
    {
        $valid = match ($key) {
            'footprint_or_area' => is_array($value) && array_keys($value) === ['kind', 'square_meters']
                && in_array($value['kind'], ['footprint', 'area'], true) && self::positiveNumber($value['square_meters']),
            'floor_count' => is_int($value) && $value >= 1 && $value <= 200,
            'floor_height' => self::positiveNumber($value) && $value <= 20,
            'region' => is_int($value) && $value >= 1,
            default => isset(self::DOMAINS[$key]) && is_string($value) && in_array($value, self::DOMAINS[$key], true),
        };
        if (! $valid) {
            throw new InvalidArgumentException('Sketch value is invalid.');
        }
    }

    private static function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }
}
