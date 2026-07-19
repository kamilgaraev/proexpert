<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkCompositionCatalog;

final readonly class DraftResidentialCompositionInspector
{
    public function __construct(
        private ResidentialWorkCompositionCatalog $catalog = new ResidentialWorkCompositionCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array{key: string, title: string, missing_items: list<string>}>
     */
    public function missingRequirements(array $draft): array
    {
        $requirements = $this->catalog->requirements($draft);
        if ($requirements === []) {
            return [];
        }

        $covered = $this->coveredItems($draft);
        $plan = is_array($draft['package_plan'] ?? null) ? $draft['package_plan'] : [];
        $packages = is_array($plan['packages'] ?? null) ? $plan['packages'] : $plan;
        $missing = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ($package['coverage_required'] ?? false) !== true) {
                continue;
            }

            $key = trim((string) ($package['key'] ?? ''));
            $requiredItems = $requirements[$key] ?? null;
            if ($key === '' || $requiredItems === null || $requiredItems === []) {
                continue;
            }

            $missingItems = array_values(array_filter(
                $requiredItems,
                static fn (string $item): bool => ! isset($covered[$key][$item]),
            ));
            if ($missingItems === []) {
                continue;
            }

            $missing[] = [
                'key' => $key,
                'title' => trim((string) ($package['title'] ?? $key)),
                'missing_items' => $missingItems,
            ];
        }

        return $missing;
    }

    /** @return array<string, array<string, true>> */
    private function coveredItems(array $draft): array
    {
        $covered = [];

        foreach ((array) ($draft['local_estimates'] ?? []) as $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }
            $packageKey = trim((string) ($localEstimate['key'] ?? ''));
            if ($packageKey === '') {
                continue;
            }

            foreach ((array) ($localEstimate['sections'] ?? []) as $section) {
                foreach (is_array($section) ? (array) ($section['work_items'] ?? []) : [] as $workItem) {
                    if (! is_array($workItem)
                        || ! in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['priced_work', 'quantity_review'], true)) {
                        continue;
                    }
                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    $identity = trim((string) (
                        $metadata['composition_work_key']
                        ?? $metadata['material_scenario_work_key']
                        ?? $metadata['quantity_key']
                        ?? $workItem['quantity_formula']
                        ?? ''
                    ));
                    if ($identity !== '') {
                        $covered[$packageKey][$identity] = true;
                    }
                }
            }
        }

        return $covered;
    }
}
