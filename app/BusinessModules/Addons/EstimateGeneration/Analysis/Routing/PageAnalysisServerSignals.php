<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

final readonly class PageAnalysisServerSignals
{
    private const MEANINGFUL_ELEMENT_TYPES = [
        'room',
        'wall',
        'opening',
        'dimension',
        'axis',
        'engineering_element',
    ];

    private const MEANINGFUL_FACT_TYPES = [
        'room',
        'wall',
        'opening',
        'axis',
        'dimension_chain',
        'elevation',
        'level',
        'area',
        'structural_element',
        'roof_geometry',
        'material',
        'finish_zone',
        'engineering_element',
        'sanitary_fixture',
        'kitchen_fixture',
        'equipment',
        'quantity',
    ];

    private function __construct(public bool $meaningfulGeometryOrEngineering) {}

    /** @param array<string, mixed> $observation */
    public static function fromLiteralObservation(array $observation): self
    {
        foreach (array_slice(is_array($observation['elements'] ?? null) ? $observation['elements'] : [], 0, 500) as $element) {
            if (is_array($element) && in_array($element['type'] ?? null, self::MEANINGFUL_ELEMENT_TYPES, true)) {
                return new self(true);
            }
        }

        $facts = is_array($observation['raw_facts'] ?? null)
            ? $observation['raw_facts']
            : ($observation['project_sheet_analysis']['facts'] ?? []);
        foreach (array_slice(is_array($facts) ? $facts : [], 0, 500) as $fact) {
            if (is_array($fact) && in_array($fact['factType'] ?? null, self::MEANINGFUL_FACT_TYPES, true)) {
                return new self(true);
            }
        }

        return new self(false);
    }

    public static function none(): self
    {
        return new self(false);
    }
}
