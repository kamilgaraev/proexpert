<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class ResidentialWorkPlanReconciler
{
    public function __construct(
        private ResidentialWorkCompositionCatalog $catalog = new ResidentialWorkCompositionCatalog,
    ) {}

    public function reconcile(
        array $plan,
        AiWorkCompositionAdviceData $advice,
        ?array $deterministicBaseline = null,
    ): array {
        $requirements = $this->catalog->requirements($plan);
        if ($requirements === []) {
            return $plan;
        }

        if ($deterministicBaseline !== null) {
            $plan = $this->restoreRequiredScope($plan, $deterministicBaseline, $requirements);
        }

        foreach ($plan['local_estimates'] as $localIndex => $localEstimate) {
            $packageKey = (string) ($localEstimate['key'] ?? '');
            foreach (($localEstimate['sections'] ?? []) as $sectionIndex => $section) {
                foreach (($section['work_items'] ?? []) as $itemIndex => $workItem) {
                    if (! is_array($workItem)) {
                        continue;
                    }
                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    $workKey = trim((string) (
                        $metadata['composition_work_key']
                        ?? $metadata['material_scenario_work_key']
                        ?? $metadata['quantity_key']
                        ?? $workItem['quantity_formula']
                        ?? ''
                    ));
                    if ($workKey === '' || ! in_array($workKey, $requirements[$packageKey] ?? [], true)) {
                        continue;
                    }
                    $decision = $advice->decisions[$workKey] ?? null;
                    $metadata['composition_coverage'] = [
                        'catalog_version' => ResidentialWorkCompositionCatalog::VERSION,
                        'required' => true,
                        'source' => is_array($decision) ? 'ai_bounded_catalog' : 'deterministic_catalog',
                        'ai_status' => is_array($decision) ? $decision['status'] : $advice->status,
                        'reason_codes' => is_array($decision) ? $decision['reason_codes'] : [],
                        'confidence' => is_array($decision) ? $decision['confidence'] : null,
                    ];
                    $workItem['metadata'] = $metadata;
                    $plan['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $workItem;
                }
            }
        }

        $plan['package_plan']['work_composition_advice'] = [
            'status' => $advice->status,
            'catalog_version' => ResidentialWorkCompositionCatalog::VERSION,
            'decision_count' => count($advice->decisions),
            'model' => $advice->model,
            'scope_decision_catalog_version' => ResidentialScopeDecisionCatalog::VERSION,
            'scope_decisions' => $advice->scopeDecisions,
        ];

        return $plan;
    }

    /**
     * @param  array<string, list<string>>  $requirements
     */
    private function synchronizeRequiredPackages(array $plan, array $baseline, array $requirements): array
    {
        $localEstimateKeys = array_fill_keys(array_values(array_filter(array_map(
            static fn (mixed $estimate): string => is_array($estimate) ? (string) ($estimate['key'] ?? '') : '',
            is_array($plan['local_estimates'] ?? null) ? $plan['local_estimates'] : [],
        ))), true);
        $packages = is_array($plan['package_plan']['packages'] ?? null)
            ? array_values(array_filter($plan['package_plan']['packages'], 'is_array'))
            : [];
        $packageIndexes = [];
        foreach ($packages as $index => $package) {
            $packageIndexes[(string) ($package['key'] ?? '')] = $index;
        }
        $baselinePackages = [];
        foreach (($baseline['package_plan']['packages'] ?? []) as $package) {
            if (! is_array($package)) {
                continue;
            }
            $baselinePackages[(string) ($package['key'] ?? '')] = $package;
        }

        foreach (array_keys($requirements) as $packageKey) {
            if (! isset($localEstimateKeys[$packageKey]) || isset($packageIndexes[$packageKey])) {
                continue;
            }
            if (! isset($baselinePackages[$packageKey])) {
                continue;
            }
            $packages[] = $baselinePackages[$packageKey];
            $packageIndexes[$packageKey] = array_key_last($packages);
        }

        $plan['package_plan']['packages'] = $packages;
        $plan['package_plan']['target_items_min_total'] = array_sum(array_map(
            static fn (array $package): int => (int) ($package['target_items_min'] ?? 0),
            $packages,
        ));
        $plan['package_plan']['target_items_max_total'] = array_sum(array_map(
            static fn (array $package): int => (int) ($package['target_items_max'] ?? 0),
            $packages,
        ));

        return $plan;
    }

    /**
     * @param  array<string, list<string>>  $requirements
     */
    private function restoreRequiredScope(array $plan, array $baseline, array $requirements): array
    {
        $currentEstimateIndexes = [];
        foreach (($plan['local_estimates'] ?? []) as $index => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }
            $currentEstimateIndexes[(string) ($localEstimate['key'] ?? '')] = $index;
        }

        foreach (($baseline['local_estimates'] ?? []) as $baselineEstimate) {
            if (! is_array($baselineEstimate)) {
                continue;
            }
            $packageKey = (string) ($baselineEstimate['key'] ?? '');
            $requiredKeys = array_fill_keys($requirements[$packageKey] ?? [], true);
            if ($requiredKeys === []) {
                continue;
            }
            if (! array_key_exists($packageKey, $currentEstimateIndexes)) {
                $requiredEstimate = $this->requiredEstimate($baselineEstimate, $requiredKeys);
                if ($requiredEstimate === null) {
                    continue;
                }
                $plan['local_estimates'][] = $requiredEstimate;
                $currentEstimateIndexes[$packageKey] = array_key_last($plan['local_estimates']);

                continue;
            }

            $currentIndex = $currentEstimateIndexes[$packageKey];
            $currentEstimate = $plan['local_estimates'][$currentIndex];
            $currentItems = $this->workItemLocations($currentEstimate);

            foreach (($baselineEstimate['sections'] ?? []) as $baselineSectionIndex => $baselineSection) {
                if (! is_array($baselineSection)) {
                    continue;
                }
                foreach (($baselineSection['work_items'] ?? []) as $baselineItem) {
                    if (! is_array($baselineItem)) {
                        continue;
                    }
                    $workKey = $this->workKey($baselineItem);
                    if ($workKey === '' || ! isset($requiredKeys[$workKey])) {
                        continue;
                    }
                    $location = $currentItems[$workKey] ?? null;
                    if ($location !== null) {
                        [$sectionIndex, $itemIndex] = $location;
                        $currentItem = $plan['local_estimates'][$currentIndex]['sections'][$sectionIndex]['work_items'][$itemIndex];
                        if (is_array($currentItem) && $this->lostQuantityContract($currentItem, $baselineItem)) {
                            $baselineMetadata = is_array($baselineItem['metadata'] ?? null)
                                ? $baselineItem['metadata']
                                : [];
                            $currentMetadata = is_array($currentItem['metadata'] ?? null)
                                ? $currentItem['metadata']
                                : [];
                            $baselineItem['metadata'] = array_replace_recursive($currentMetadata, $baselineMetadata);
                            $plan['local_estimates'][$currentIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $baselineItem;
                        }

                        continue;
                    }

                    $targetSectionIndex = $this->matchingSectionIndex(
                        $plan['local_estimates'][$currentIndex],
                        $baselineSection,
                        $baselineSectionIndex,
                    );
                    $plan['local_estimates'][$currentIndex]['sections'][$targetSectionIndex]['work_items'][] = $baselineItem;
                    $currentItems[$workKey] = [
                        $targetSectionIndex,
                        array_key_last($plan['local_estimates'][$currentIndex]['sections'][$targetSectionIndex]['work_items']),
                    ];
                }
            }
        }

        return $this->synchronizeRequiredPackages($plan, $baseline, $requirements);
    }

    /**
     * @param  array<string, true>  $requiredKeys
     */
    private function requiredEstimate(array $baselineEstimate, array $requiredKeys): ?array
    {
        $sections = [];
        foreach (($baselineEstimate['sections'] ?? []) as $baselineSection) {
            if (! is_array($baselineSection)) {
                continue;
            }
            $workItems = array_values(array_filter(
                is_array($baselineSection['work_items'] ?? null) ? $baselineSection['work_items'] : [],
                fn (mixed $workItem): bool => is_array($workItem)
                    && isset($requiredKeys[$this->workKey($workItem)]),
            ));
            if ($workItems === []) {
                continue;
            }
            $baselineSection['work_items'] = $workItems;
            $sections[] = $baselineSection;
        }
        if ($sections === []) {
            return null;
        }
        $baselineEstimate['sections'] = $sections;

        return $baselineEstimate;
    }

    /** @return array<string, array{int, int}> */
    private function workItemLocations(array $localEstimate): array
    {
        $locations = [];
        foreach (($localEstimate['sections'] ?? []) as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach (($section['work_items'] ?? []) as $itemIndex => $workItem) {
                if (! is_array($workItem)) {
                    continue;
                }
                $workKey = $this->workKey($workItem);
                if ($workKey !== '') {
                    $locations[$workKey] = [(int) $sectionIndex, (int) $itemIndex];
                }
            }
        }

        return $locations;
    }

    private function workKey(array $workItem): string
    {
        $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];

        return trim((string) (
            $metadata['composition_work_key']
            ?? $metadata['material_scenario_work_key']
            ?? $metadata['quantity_key']
            ?? $workItem['quantity_formula']
            ?? ''
        ));
    }

    private function lostQuantityContract(array $currentItem, array $baselineItem): bool
    {
        $baselineMetadata = is_array($baselineItem['metadata'] ?? null) ? $baselineItem['metadata'] : [];
        $currentMetadata = is_array($currentItem['metadata'] ?? null) ? $currentItem['metadata'] : [];
        $baselineHasCanonicalKey = trim((string) (
            $baselineMetadata['quantity_key']
            ?? $baselineMetadata['material_scenario_work_key']
            ?? $baselineItem['quantity_formula']
            ?? ''
        )) !== '';
        $currentHasCanonicalKey = trim((string) (
            $currentMetadata['quantity_key']
            ?? $currentMetadata['material_scenario_work_key']
            ?? $currentItem['quantity_formula']
            ?? ''
        )) !== '';
        $baselineUnit = mb_strtolower(trim((string) ($baselineItem['unit'] ?? '')));
        $currentUnit = mb_strtolower(trim((string) ($currentItem['unit'] ?? '')));

        return $baselineHasCanonicalKey
            && (! $currentHasCanonicalKey || ($baselineUnit !== '' && $baselineUnit !== 'компл' && $currentUnit === 'компл'));
    }

    private function matchingSectionIndex(array &$currentEstimate, array $baselineSection, int $fallbackIndex): int
    {
        $baselineKey = (string) ($baselineSection['key'] ?? '');
        foreach (($currentEstimate['sections'] ?? []) as $sectionIndex => $currentSection) {
            if (is_array($currentSection) && $baselineKey !== '' && ($currentSection['key'] ?? null) === $baselineKey) {
                return (int) $sectionIndex;
            }
        }
        if (isset($currentEstimate['sections'][$fallbackIndex])) {
            return $fallbackIndex;
        }

        $currentEstimate['sections'][] = array_replace($baselineSection, ['work_items' => []]);

        return (int) array_key_last($currentEstimate['sections']);
    }
}
