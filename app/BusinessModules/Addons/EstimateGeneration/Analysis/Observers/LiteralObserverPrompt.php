<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

final class LiteralObserverPrompt
{
    public const VERSION = 'observer-literal:v1';

    public static function text(): string
    {
        return implode("\n", [
            'Read only what is explicitly visible in the supplied construction page and verified native representations.',
            'Capture every label, dimension, table row, mark, note, legend and cross-sheet reference with an exact source locator.',
            'Do not infer a construction solution. Preserve unfamiliar professional wording as an observation instead of replacing it with a generic clarification.',
        ]);
    }
}
