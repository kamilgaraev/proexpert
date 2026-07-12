<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final readonly class SketchClarificationData
{
    public function __construct(public array $answers)
    {
        if (! array_is_list($answers)) {
            throw new InvalidArgumentException('Sketch answers must be a list.');
        }
        $keys = [];
        foreach ($answers as $answer) {
            if (! $answer instanceof SketchAssumption || isset($keys[$answer->key])) {
                throw new InvalidArgumentException('Sketch answer is invalid or duplicated.');
            }
            $keys[$answer->key] = true;
        }
    }
}
