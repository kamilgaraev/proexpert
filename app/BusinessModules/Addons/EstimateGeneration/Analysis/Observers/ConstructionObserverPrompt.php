<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

final class ConstructionObserverPrompt
{
    public const VERSION = 'observer-construction:v4';

    public static function text(): string
    {
        return implode("\n", [
            'Independently interpret the construction meaning of the supplied page without seeing any other observer result.',
            'Identify structures, materials, layers, element purposes, interfaces and plausible work scope, keeping explicit facts separate from visual inference.',
            'On plans preserve sanitary fixtures, kitchen fixtures/equipment and furniture as evidence-backed visual inventory; conditional furniture is context, while fixtures require a later server-side scope decision.',
            'Keep every conclusion linked to a page region or verified native reference and preserve unusual assemblies in full.',
            'Use sheet-analysis:v4. Optional semanticProposal is a source proposal only: equipment_identity on equipment_specification, or source_requirement on an eligible document-context or work-scope string fact.',
            'For source_requirement preserve the source subject, exact decimal threshold string, length unit, gte relation, edition, clause and conditions. Equipment identity is optional for general construction requirements. Unknown fields use data=null and null references; known fields without their own locators are inferred.',
            'Attach each known source-backed field to its own evidenceRef and sourcePolygonOrNativeRef. Never copy the excerpt locator as proof of all fields. Do not supply target bindings, IDs, acceptance, verification, applicability or trust flags. A source proposal does not prove the current target or any observed measurement.',
        ]);
    }
}
