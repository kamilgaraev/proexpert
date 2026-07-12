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
                || count($this->sections) === 1
                || ($section['scope_type'] ?? null) === $scopeType) {
                return $section['work_intents'];
            }
        }

        return null;
    }
}
