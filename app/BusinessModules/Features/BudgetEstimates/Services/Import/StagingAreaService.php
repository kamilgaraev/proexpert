<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Support\Facades\Log;

class StagingAreaService
{
    public function __construct(
        private readonly EstimateImportService $importService,
        private readonly FormulaAwarenessService $formulaAwareness,
        private readonly AnomalyDetectionService $anomalyDetector,
        private readonly SubItemGroupingService $subItemGrouper,
    ) {}

    public function buildPreview(string $sessionId, int $organizationId): array
    {
        $preview = $this->importService->preview($sessionId);
        $rows = array_merge($preview->sections, $preview->items);

        usort(
            $rows,
            static fn (array $left, array $right): int => ($left['row_number'] ?? 0) <=> ($right['row_number'] ?? 0)
        );

        $grouped = $this->subItemGrouper->groupItems($rows);

        $this->formulaAwareness->annotate($grouped);
        $this->anomalyDetector->annotateFromImport($grouped, $organizationId);

        $stats = $this->buildStats($grouped);
        $treeRows = $this->buildFrontendTree($grouped);

        Log::info('[StagingArea] Preview built', [
            'session_id' => $sessionId,
            'stats' => $stats,
        ]);

        return [
            'session_id' => $sessionId,
            'rows' => $treeRows,
            'stats' => $stats,
        ];
    }

    private function buildStats(array $rows): array
    {
        $sections = 0;
        $items = 0;
        $anomalies = 0;
        $mismatches = 0;

        foreach ($rows as $row) {
            if ($row['is_section'] ?? false) {
                $sections++;
                continue;
            }

            $items++;
            if (!empty($row['anomaly']['is_anomaly'])) {
                $anomalies++;
            }

            if (!empty($row['has_math_mismatch'])) {
                $mismatches++;
            }
        }

        return [
            'total' => count($rows),
            'sections' => $sections,
            'items' => $items,
            'anomalies' => $anomalies,
            'mismatches' => $mismatches,
        ];
    }

    private function buildFrontendTree(array $flatRows): array
    {
        $tree = [];
        $insertedParents = [];

        foreach ($flatRows as $idx => $row) {
            if ($row['is_section'] ?? false) {
                $tree[] = $row;
                continue;
            }

            if (!empty($row['is_sub_item'])) {
                $parentIdx = $row['_parent_index'] ?? null;
                if ($parentIdx !== null && isset($insertedParents[$parentIdx])) {
                    $insertedParents[$parentIdx]['sub_items'] ??= [];
                    $insertedParents[$parentIdx]['sub_items'][] = $row;
                    continue;
                }

                $tree[] = $row;
                continue;
            }

            $tree[] = $row;
            $insertedParents[$idx] = &$tree[count($tree) - 1];
        }

        return $tree;
    }
}
