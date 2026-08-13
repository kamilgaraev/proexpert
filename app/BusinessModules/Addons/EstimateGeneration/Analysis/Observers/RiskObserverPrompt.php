<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

final class RiskObserverPrompt
{
    public const VERSION = 'observer-risk:v1';

    public static function text(): string
    {
        return implode("\n", [
            'Independently search the supplied construction page for contradictions, alternatives, missing materials and estimate-significant uncertainty.',
            'Check constraints, notes, climate or normative implications and cross-sheet dependencies without seeing any other observer result.',
            'Return concrete risks, recommendations and evidence; do not emit a generic request to clarify.',
        ]);
    }
}
