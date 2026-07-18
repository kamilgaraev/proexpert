<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

interface NormativeContextPinSource
{
    /** @param non-empty-list<array{search_text: string, unit: string, code?: string|null, action?: string|null, normative_section?: string|null, normative_sections?: list<string>}> $intents */
    public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData;
}
