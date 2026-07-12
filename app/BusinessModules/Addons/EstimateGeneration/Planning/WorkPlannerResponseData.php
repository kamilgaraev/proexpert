<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class WorkPlannerResponseData
{
    /** @param list<array<string, mixed>> $sections */
    public function __construct(public array $sections) {}

    /** @return list<array<string, mixed>>|null */
    public function intentsFor(string $sectionKey, string $scopeType): ?array
    {
        foreach ($this->sections as $section) {
            if (($section['section_key'] ?? null) === $sectionKey
                || (($section['scope_type'] ?? null) === $scopeType && count($this->sections) === 1)) {
                return $section['work_intents'];
            }
        }

        return null;
    }
}
