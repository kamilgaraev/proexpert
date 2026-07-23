<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final class DraftPackageCoverageInspector
{
    /**
     * @param  array<string, mixed>  $draft
     * @return list<array{key: string, title: string}>
     */
    public function missingPackages(array $draft): array
    {
        $coveredPackages = [];

        foreach ((array) ($draft['local_estimates'] ?? []) as $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            $packageKey = trim((string) ($localEstimate['key'] ?? ''));
            if ($packageKey === '' || ! $this->hasVisibleScopeItem($localEstimate)) {
                continue;
            }

            $coveredPackages[$packageKey] = true;
        }

        $packagePlan = is_array($draft['package_plan'] ?? null) ? $draft['package_plan'] : [];
        $packages = is_array($packagePlan['packages'] ?? null) ? $packagePlan['packages'] : $packagePlan;
        $missing = [];
        foreach ($packages as $package) {
            if (! is_array($package) || ($package['coverage_required'] ?? false) !== true) {
                continue;
            }

            $key = trim((string) ($package['key'] ?? ''));
            if ($key === '' || isset($coveredPackages[$key])) {
                continue;
            }

            $missing[] = [
                'key' => $key,
                'title' => trim((string) ($package['title'] ?? $key)),
            ];
        }

        return $missing;
    }

    /** @param array<string, mixed> $localEstimate */
    private function hasVisibleScopeItem(array $localEstimate): bool
    {
        foreach ((array) ($localEstimate['sections'] ?? []) as $section) {
            foreach (is_array($section) ? (array) ($section['work_items'] ?? []) : [] as $workItem) {
                if (! is_array($workItem)) {
                    continue;
                }

                if (in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['priced_work', 'quantity_review'], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
