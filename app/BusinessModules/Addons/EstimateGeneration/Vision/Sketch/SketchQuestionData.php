<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final readonly class SketchQuestionData
{
    public const KEYS = ['footprint_or_area', 'floor_count', 'floor_height', 'wall_material', 'foundation_type', 'roof_type', 'finish_level', 'region'];

    public function __construct(public string $key)
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException('Sketch question is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['key' => $this->key];
    }
}
