<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\EstimateVersion;
use Illuminate\Support\Facades\Log;

class StructuralDiffService
{
    public function diff(int $versionAId, int $versionBId): array
    {
        $versionA = EstimateVersion::findOrFail($versionAId);
        $versionB = EstimateVersion::findOrFail($versionBId);

        if ($versionA->estimate_id !== $versionB->estimate_id) {
            throw new \InvalidArgumentException('Нельзя сравнивать версии разных смет');
        }

        $snapshotA = $versionA->snapshot;
        $snapshotB = $versionB->snapshot;

        $itemsA = $this->flattenItems($snapshotA['sections'] ?? []);
        $itemsB = $this->flattenItems($snapshotB['sections'] ?? []);

        $added   = [];
        $removed = [];
        $changed = [];
        $unchanged = 0;

        $idsA = array_column($itemsA, 'id');
        $idsB = array_column($itemsB, 'id');
        $mapA = array_column($itemsA, null, 'id');
        $mapB = array_column($itemsB, null, 'id');

        foreach ($idsB as $id) {
            if (!in_array($id, $idsA, true)) {
                $added[] = array_merge($mapB[$id], ['_diff' => 'added']);
                continue;
            }

            $itemA = $mapA[$id];
            $itemB = $mapB[$id];
            $delta = $this->computeItemDelta($itemA, $itemB);

            if (empty($delta)) {
                $unchanged++;
            } else {
                $changed[] = [
                    '_diff'   => 'changed',
                    'id'      => $id,
                    'name'    => $itemB['name'],
                    'changes' => $delta,
                ];
            }
        }

        foreach ($idsA as $id) {
            if (!in_array($id, $idsB, true)) {
                $removed[] = array_merge($mapA[$id], ['_diff' => 'removed']);
            }
        }

        $totalDeltaAmount = ($snapshotB['totals']['total_amount'] ?? 0)
            - ($snapshotA['totals']['total_amount'] ?? 0);

        Log::info("[StructuralDiff] v{$versionA->version_number} vs v{$versionB->version_number}: " .
            count($added) . " added, " . count($removed) . " removed, " . count($changed) . " changed");

        return [
            'version_a' => [
                'id'             => $versionA->id,
                'version_number' => $versionA->version_number,
                'label'          => $versionA->label,
                'total_amount'   => $versionA->total_amount,
            ],
            'version_b' => [
                'id'             => $versionB->id,
                'version_number' => $versionB->version_number,
                'label'          => $versionB->label,
                'total_amount'   => $versionB->total_amount,
            ],
            'summary' => [
                'added'              => count($added),
                'removed'            => count($removed),
                'changed'            => count($changed),
                'unchanged'          => $unchanged,
                'total_delta_amount' => round($totalDeltaAmount, 2),
                'total_delta_pct'    => $versionA->total_amount > 0
                    ? round($totalDeltaAmount / $versionA->total_amount * 100, 2)
                    : null,
            ],
            'added'   => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    public function diffEstimates(int $estimateIdA, int $estimateIdB): array
    {
        $latestA = EstimateVersion::where('estimate_id', $estimateIdA)->orderByDesc('version_number')->firstOrFail();
        $latestB = EstimateVersion::where('estimate_id', $estimateIdB)->orderByDesc('version_number')->firstOrFail();

        return $this->diff($latestA->id, $latestB->id);
    }

    private function flattenItems(array $sections): array
    {
        $items = [];
        foreach ($sections as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function computeItemDelta(array $a, array $b): array
    {
        $watchFields = ['quantity', 'unit_price', 'base_unit_price', 'price_index', 'total_amount', 'current_total', 'name', 'unit'];
        $delta = [];

        foreach ($watchFields as $field) {
            $valA = $a[$field] ?? null;
            $valB = $b[$field] ?? null;

            if ($valA === $valB) {
                continue;
            }

            if (is_numeric($valA) && is_numeric($valB)) {
                $delta[$field] = [
                    'before'  => (float)$valA,
                    'after'   => (float)$valB,
                    'delta'   => round((float)$valB - (float)$valA, 4),
                    'delta_pct' => $valA != 0 ? round(((float)$valB - (float)$valA) / abs((float)$valA) * 100, 2) : null,
                ];
            } else {
                $delta[$field] = ['before' => $valA, 'after' => $valB];
            }
        }

        return $delta;
    }
}
