<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkCompositionCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;

final readonly class EstimateCompletenessProfile
{
    public function __construct(
        private ResidentialWorkCompositionCatalog $catalog = new ResidentialWorkCompositionCatalog,
    ) {}

    /** @return array<string, mixed> */
    public function project(array $draft): array
    {
        $requirements = $this->catalog->requirements($draft);
        $packages = $this->requiredPackages($draft, $requirements);
        $coveredWorkKeys = $this->coveredWorkKeys($draft);
        $exclusions = $this->explicitExclusions($draft, array_keys($packages));
        $scopes = [];

        foreach ($packages as $key => $package) {
            $requiredItems = $requirements[$key] ?? [];
            $coveredItems = array_values(array_filter(
                $requiredItems,
                static fn (string $item): bool => isset($coveredWorkKeys[$key][$item]),
            ));
            $missingItems = array_values(array_filter(
                $requiredItems,
                static fn (string $item): bool => ! isset($coveredWorkKeys[$key][$item]),
            ));
            $exclusion = $exclusions[$key] ?? null;
            $gaps = $this->warningGaps($draft, $key);
            $warningWorkKeys = array_fill_keys(array_column($gaps, 'work_key'), true);
            if ($exclusion === null) {
                foreach ($missingItems as $item) {
                    if (isset($warningWorkKeys[$item])) {
                        continue;
                    }

                    $gaps[] = ['work_key' => $item, 'reason' => 'document_takeoff_missing'];
                }
            }

            $scopes[$key] = [
                'key' => $key,
                'title' => $package['title'],
                'requested' => $exclusion === null,
                'state' => $exclusion !== null
                    ? 'excluded'
                    : ($missingItems === [] ? 'covered' : 'unresolved'),
                'required_items' => $requiredItems,
                'covered_items' => $coveredItems,
                'missing_items' => $exclusion === null ? $missingItems : [],
                'gaps' => $gaps,
                'evidence_refs' => $exclusion['evidence_refs'] ?? [],
                'exclusion_reason' => $exclusion['reason'] ?? null,
            ];
        }

        $records = array_values($scopes);
        $covered = array_values(array_filter(
            $records,
            static fn (array $scope): bool => $scope['state'] === 'covered',
        ));
        $unresolved = array_values(array_filter(
            $records,
            static fn (array $scope): bool => $scope['state'] === 'unresolved',
        ));
        $excluded = array_values(array_filter(
            $records,
            static fn (array $scope): bool => $scope['state'] === 'excluded',
        ));

        return [
            'status' => $unresolved !== []
                ? 'confirmed_scope_only'
                : ($records === [] ? 'review_required' : 'full_confirmed_scope'),
            'scopes' => $scopes,
            'covered' => $covered,
            'unresolved' => $unresolved,
            'excluded' => $excluded,
        ];
    }

    /** @param array<string, list<string>> $requirements
     *  @return array<string, array{title: string}>
     */
    private function requiredPackages(array $draft, array $requirements): array
    {
        $plan = is_array($draft['package_plan'] ?? null) ? $draft['package_plan'] : [];
        $packages = is_array($plan['packages'] ?? null) ? $plan['packages'] : $plan;
        $required = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ($package['coverage_required'] ?? false) !== true) {
                continue;
            }

            $key = trim((string) ($package['key'] ?? ''));
            if ($key === '' || ! array_key_exists($key, $requirements)) {
                continue;
            }

            $required[$key] = ['title' => trim((string) ($package['title'] ?? $key))];
        }

        ksort($required, SORT_STRING);

        return $required;
    }

    /** @return array<string, array<string, true>> */
    private function coveredWorkKeys(array $draft): array
    {
        $covered = [];

        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            if (! is_array($estimate)) {
                continue;
            }

            $packageKey = trim((string) ($estimate['key'] ?? ''));
            if ($packageKey === '') {
                continue;
            }

            foreach ((array) ($estimate['sections'] ?? []) as $section) {
                foreach (is_array($section) ? (array) ($section['work_items'] ?? []) : [] as $workItem) {
                    if (! is_array($workItem)
                        || ($workItem['item_type'] ?? 'priced_work') !== 'priced_work'
                        || ($workItem['pricing_status'] ?? null) !== 'calculated') {
                        continue;
                    }

                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    $key = trim((string) (
                        $metadata['composition_work_key']
                        ?? $metadata['material_scenario_work_key']
                        ?? $metadata['quantity_key']
                        ?? $workItem['quantity_formula']
                        ?? ''
                    ));
                    if ($key !== '') {
                        $covered[$packageKey][$key] = true;
                    }
                }
            }
        }

        return $covered;
    }

    /** @return list<array{work_key: string, reason: string}> */
    private function warningGaps(array $draft, string $packageKey): array
    {
        $gaps = [];
        $seen = [];

        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            if (! is_array($estimate) || trim((string) ($estimate['key'] ?? '')) !== $packageKey) {
                continue;
            }

            foreach ((array) ($estimate['coverage_warnings'] ?? []) as $warning) {
                if (! QuantityCoverageWarning::isValid($warning)
                    || $warning['package_key'] !== $packageKey) {
                    continue;
                }

                $workKey = $warning['quantity_key'];
                $reason = $warning['reason'];
                $pair = $workKey."\0".$reason;
                if (isset($seen[$pair])) {
                    continue;
                }

                $seen[$pair] = true;
                $gaps[] = ['work_key' => $workKey, 'reason' => $reason];
            }
        }

        return $gaps;
    }

    /** @param list<string> $packageKeys
     *  @return array<string, array{reason: string, evidence_refs: list<string>}>
     */
    private function explicitExclusions(array $draft, array $packageKeys): array
    {
        $allowed = array_fill_keys($packageKeys, true);
        $exclusions = [];

        foreach ((array) ($draft['completeness_exclusions'] ?? []) as $key => $exclusion) {
            if (! is_string($key) || ! isset($allowed[$key]) || ! is_array($exclusion)) {
                continue;
            }

            $reason = (string) ($exclusion['reason'] ?? '');
            $evidenceRefs = array_values(array_unique(array_filter(
                array_map('strval', (array) ($exclusion['evidence_refs'] ?? [])),
                static fn (string $reference): bool => trim($reference) !== '',
            )));
            if (! in_array($reason, ['user_decision', 'document'], true) || $evidenceRefs === []) {
                continue;
            }

            $exclusions[$key] = [
                'reason' => $reason,
                'evidence_refs' => $evidenceRefs,
            ];
        }

        return $exclusions;
    }
}
