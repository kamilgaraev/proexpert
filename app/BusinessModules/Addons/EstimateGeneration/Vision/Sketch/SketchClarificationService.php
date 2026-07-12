<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final class SketchClarificationService
{
    private const QUESTIONS = [
        'footprint_or_area', 'floor_count', 'floor_height', 'wall_material',
        'foundation_type', 'roof_type', 'finish_level', 'region',
    ];

    public function missingQuestions(SketchClarificationData $input): array
    {
        return array_values(array_map(
            static fn (string $key): array => ['key' => $key],
            array_filter(self::QUESTIONS, static fn (string $key): bool => ! array_key_exists($key, $input->answers)),
        ));
    }

    public function assumption(string $key, int|float|string $value, string $source, float $confidence, ?string $evidenceId, bool $confirmed): SketchAssumption
    {
        new SketchClarificationData([$key => $value]);
        if (! in_array($source, ['user', 'catalog_default'], true) || ! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Sketch assumption is invalid.');
        }
        if ($source === 'catalog_default') {
            return new SketchAssumption($key, $value, $source, $confidence, null, true, false);
        }
        if ($confirmed && ($evidenceId === null || $evidenceId === '')) {
            throw new InvalidArgumentException('Confirmed sketch assumption requires evidence.');
        }

        return new SketchAssumption($key, $value, $source, $confidence, $evidenceId, ! $confirmed, $confirmed);
    }
}
