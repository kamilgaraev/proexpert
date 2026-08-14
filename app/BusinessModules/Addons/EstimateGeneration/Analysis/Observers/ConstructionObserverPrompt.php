<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

final class ConstructionObserverPrompt
{
    public const VERSION = 'observer-construction:v3';

    public static function text(): string
    {
        return implode("\n", [
            'Independently interpret the construction meaning of the supplied page without seeing any other observer result.',
            'Identify structures, materials, layers, element purposes, interfaces and plausible work scope, keeping explicit facts separate from visual inference.',
            'Keep every conclusion linked to a page region or verified native reference and preserve unusual assemblies in full.',
        ]);
    }
}
