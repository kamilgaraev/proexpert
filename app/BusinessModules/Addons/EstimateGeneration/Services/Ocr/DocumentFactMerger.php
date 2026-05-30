<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\ExtractedDocumentFact;

class DocumentFactMerger
{
    /**
     * @param array<int, ExtractedDocumentFact> $facts
     * @return array<string, mixed>
     */
    public function summarize(array $facts): array
    {
        $summary = [
            'total_area_m2' => null,
            'floor_count' => null,
            'zones' => [],
            'engineering_systems' => [],
            'conflicts' => [],
        ];

        $totalAreas = [];
        $floorCounts = [];

        foreach ($facts as $fact) {
            if ($fact->factType === 'total_area' && $fact->valueNumber !== null) {
                $totalAreas[] = $fact;
            }

            if ($fact->factType === 'floor_count' && $fact->valueNumber !== null) {
                $floorCounts[] = $fact;
            }

            if ($fact->factType === 'zone_area' && $fact->valueNumber !== null) {
                $summary['zones'][] = [
                    'scope_key' => $fact->scopeKey,
                    'label' => $fact->label,
                    'area_m2' => $fact->valueNumber,
                    'confidence' => $fact->confidence,
                    'source_ref' => $fact->sourceRef,
                ];
            }

            if ($fact->factType === 'engineering_system') {
                $summary['engineering_systems'][] = [
                    'key' => $fact->scopeKey,
                    'label' => $fact->label,
                    'confidence' => $fact->confidence,
                    'source_ref' => $fact->sourceRef,
                ];
            }
        }

        $summary['total_area_m2'] = $this->bestNumber($totalAreas);
        $summary['floor_count'] = $this->bestNumber($floorCounts);
        $summary['conflicts'] = array_merge(
            $this->numberConflicts('total_area_m2', $totalAreas, 1.0),
            $this->numberConflicts('floor_count', $floorCounts, 0.0),
        );
        $summary['engineering_systems'] = $this->uniqueByKey($summary['engineering_systems']);

        return $summary;
    }

    /**
     * @param array<int, ExtractedDocumentFact> $facts
     */
    private function bestNumber(array $facts): ?float
    {
        if ($facts === []) {
            return null;
        }

        usort($facts, static function (ExtractedDocumentFact $a, ExtractedDocumentFact $b): int {
            $rankComparison = ((int) ($a->normalizedPayload['source_rank'] ?? PHP_INT_MAX))
                <=> ((int) ($b->normalizedPayload['source_rank'] ?? PHP_INT_MAX));

            if ($rankComparison !== 0) {
                return $rankComparison;
            }

            return $b->confidence <=> $a->confidence;
        });

        return $facts[0]->valueNumber;
    }

    /**
     * @param array<int, ExtractedDocumentFact> $facts
     * @return array<int, array<string, mixed>>
     */
    private function numberConflicts(string $field, array $facts, float $tolerance): array
    {
        if (count($facts) < 2) {
            return [];
        }

        $values = array_values(array_unique(array_map(
            static fn (ExtractedDocumentFact $fact): ?float => $fact->valueNumber,
            $facts,
        )));

        if (count($values) < 2) {
            return [];
        }

        $min = min($values);
        $max = max($values);

        if (($max - $min) <= $tolerance) {
            return [];
        }

        return [[
            'field' => $field,
            'values' => array_map(static fn (ExtractedDocumentFact $fact): array => [
                'value' => $fact->valueNumber,
                'confidence' => $fact->confidence,
                'source_ref' => $fact->sourceRef,
            ], $facts),
        ]];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function uniqueByKey(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['label'] ?? '');
            $unique[$key] = $item;
        }

        return array_values($unique);
    }
}
