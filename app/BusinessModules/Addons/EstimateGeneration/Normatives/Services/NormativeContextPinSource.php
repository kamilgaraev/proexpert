<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

interface NormativeContextPinSource
{
    /** @param non-empty-list<array{search_text: string, unit: string, code?: string|null}> $intents */
    public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData;
}
