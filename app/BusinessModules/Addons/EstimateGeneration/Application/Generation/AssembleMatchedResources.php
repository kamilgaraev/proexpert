<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

final class AssembleMatchedResources
{
    private const GROUPS = ['materials', 'labor', 'machinery', 'other_resources'];

    /** @param array<string, mixed> $data @return array{data: array<string, mixed>, resources_count: int} */
    public function handle(array $data): array
    {
        $count = 0;
        foreach ($data['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                foreach ($section['work_items'] as $itemIndex => $workItem) {
                    foreach (self::GROUPS as $group) {
                        $resources = is_array($workItem[$group] ?? null)
                            ? array_values(array_filter($workItem[$group], 'is_array'))
                            : [];
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex][$group] = $resources;
                        $count += count($resources);
                    }
                }
            }
        }

        return ['data' => $data, 'resources_count' => $count];
    }
}
