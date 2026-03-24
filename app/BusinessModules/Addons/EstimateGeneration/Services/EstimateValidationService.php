<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class EstimateValidationService
{
    public function validate(array $draft): array
    {
        $projectFlags = [];
        $confidenceSum = 0.0;
        $confidenceCount = 0;
        $totalCost = 0.0;

        foreach ($draft['local_estimates'] as $localIndex => $localEstimate) {
            $localFlags = [];
            if ($localEstimate['source_refs'] === []) {
                $localFlags[] = 'weak_source_reference';
            }

            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $sectionFlags = [];
                if (mb_strtolower($section['title']) === 'прочее') {
                    $sectionFlags[] = 'generic_section_name';
                }

                $sectionTotal = 0.0;
                foreach ($section['work_items'] as $workIndex => $workItem) {
                    $flags = [];
                    if (($workItem['quantity_basis'] ?? null) === null || $workItem['quantity_basis'] === '') {
                        $flags[] = 'missing_quantity_basis';
                    }
                    if ((float) ($workItem['total_cost'] ?? 0) <= 0) {
                        $flags[] = 'missing_price';
                    }
                    if (($workItem['materials'] ?? []) === [] && ($workItem['labor'] ?? []) === [] && ($workItem['machinery'] ?? []) === []) {
                        $flags[] = 'missing_resources';
                    }
                    if ((float) ($workItem['quantity'] ?? 0) <= 0) {
                        $flags[] = 'suspicious_quantity';
                    }
                    if ((float) ($workItem['confidence'] ?? 0) < 0.6) {
                        $flags[] = 'low_confidence';
                    }

                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$workIndex]['validation_flags'] = $flags;
                    $sectionTotal += (float) ($workItem['total_cost'] ?? 0);
                    $confidenceSum += (float) ($workItem['confidence'] ?? 0);
                    $confidenceCount++;
                }

                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['validation_flags'] = $sectionFlags;
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['section_totals'] = [
                    'total_cost' => round($sectionTotal, 2),
                    'items_count' => count($section['work_items']),
                ];

                $totalCost += $sectionTotal;
                $localFlags = array_values(array_unique([...$localFlags, ...$sectionFlags]));
            }

            $draft['local_estimates'][$localIndex]['validation_flags'] = $localFlags;
            $draft['local_estimates'][$localIndex]['totals'] = [
                'total_cost' => round(array_sum(array_map(
                    static fn (array $section): float => (float) ($section['section_totals']['total_cost'] ?? 0),
                    $draft['local_estimates'][$localIndex]['sections']
                )), 2),
                'sections_count' => count($draft['local_estimates'][$localIndex]['sections']),
            ];

            $projectFlags = array_values(array_unique([...$projectFlags, ...$localFlags]));
        }

        $draft['totals'] = [
            'total_cost' => round($totalCost, 2),
            'local_estimates_count' => count($draft['local_estimates']),
            'work_items_count' => array_sum(array_map(
                static fn (array $localEstimate): int => array_sum(array_map(
                    static fn (array $section): int => count($section['work_items']),
                    $localEstimate['sections']
                )),
                $draft['local_estimates']
            )),
        ];
        $draft['confidence'] = [
            'average' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 4) : 0,
        ];
        $draft['problem_flags'] = $projectFlags;

        return $draft;
    }
}
