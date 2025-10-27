<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Audit;

use App\Models\Estimate;
use App\Models\EstimateChangeLog;
use App\Models\EstimateSnapshot;
use App\Models\EstimateComparisonCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Database\Eloquent\Collection;

class EstimateAuditService
{
    public function logChange(
        Estimate $estimate,
        string $changeType,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $comment = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): EstimateChangeLog {
        return EstimateChangeLog::create([
            'estimate_id' => $estimate->id,
            'user_id' => Auth::id(),
            'change_type' => $changeType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'comment' => $comment,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'changed_at' => now(),
        ]);
    }

    public function createSnapshot(
        Estimate $estimate,
        string $snapshotType = 'manual',
        ?string $label = null,
        ?string $description = null
    ): EstimateSnapshot {
        $snapshotData = $this->prepareSnapshotData($estimate);
        $serializedData = json_encode($snapshotData);
        $dataSize = strlen($serializedData);

        $snapshot = EstimateSnapshot::create([
            'estimate_id' => $estimate->id,
            'created_by_user_id' => Auth::id(),
            'snapshot_type' => $snapshotType,
            'label' => $label,
            'description' => $description,
            'snapshot_data' => $snapshotData,
            'data_size' => $dataSize,
            'checksum' => hash('sha256', $serializedData),
            'created_at' => now(),
        ]);

        return $snapshot;
    }

    public function compareEstimates(Estimate $estimate1, Estimate $estimate2): array
    {
        $cached = EstimateComparisonCache::forEstimates($estimate1->id, $estimate2->id)
            ->notExpired()
            ->first();

        if ($cached) {
            return $cached->diff_data;
        }

        $diff = $this->performComparison($estimate1, $estimate2);

        EstimateComparisonCache::create([
            'estimate_id_1' => $estimate1->id,
            'estimate_id_2' => $estimate2->id,
            'comparison_type' => 'full',
            'diff_data' => $diff,
            'summary' => $this->generateComparisonSummary($diff),
            'created_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        return $diff;
    }

    public function compareSnapshots(EstimateSnapshot $snapshot1, EstimateSnapshot $snapshot2): array
    {
        $data1 = $snapshot1->snapshot_data;
        $data2 = $snapshot2->snapshot_data;

        return $this->compareData($data1, $data2);
    }

    public function restoreFromSnapshot(Estimate $estimate, EstimateSnapshot $snapshot): Estimate
    {
        if (!$snapshot->verifyIntegrity()) {
            throw new \RuntimeException('Контрольная сумма снимка не совпадает. Данные могли быть повреждены.');
        }

        $this->createSnapshot($estimate, 'before_major_change', 'Перед восстановлением из снимка');

        $snapshotData = $snapshot->snapshot_data;

        $estimate->update([
            'name' => $snapshotData['name'] ?? $estimate->name,
            'description' => $snapshotData['description'] ?? $estimate->description,
            'total_direct_costs' => $snapshotData['total_direct_costs'] ?? 0,
            'total_overhead_costs' => $snapshotData['total_overhead_costs'] ?? 0,
            'total_estimated_profit' => $snapshotData['total_estimated_profit'] ?? 0,
            'total_amount' => $snapshotData['total_amount'] ?? 0,
            'total_amount_with_vat' => $snapshotData['total_amount_with_vat'] ?? 0,
            'vat_rate' => $snapshotData['vat_rate'] ?? $estimate->vat_rate,
            'overhead_rate' => $snapshotData['overhead_rate'] ?? $estimate->overhead_rate,
            'profit_rate' => $snapshotData['profit_rate'] ?? $estimate->profit_rate,
        ]);

        $this->logChange(
            $estimate,
            'restored_from_snapshot',
            null,
            null,
            "Восстановлено из снимка ID: {$snapshot->id}",
            'estimate',
            $estimate->id
        );

        return $estimate->fresh();
    }

    public function getChangeHistory(
        Estimate $estimate,
        array $filters = []
    ): Collection {
        $query = EstimateChangeLog::where('estimate_id', $estimate->id);

        if (!empty($filters['change_type'])) {
            $query->where('change_type', $filters['change_type']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['from_date'])) {
            $query->where('changed_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('changed_at', '<=', $filters['to_date']);
        }

        return $query->with('user')
            ->orderBy('changed_at', 'desc')
            ->limit($filters['limit'] ?? 100)
            ->get();
    }

    public function getSnapshots(Estimate $estimate, ?string $type = null): Collection
    {
        $query = EstimateSnapshot::where('estimate_id', $estimate->id);

        if ($type) {
            $query->where('snapshot_type', $type);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function cleanupOldSnapshots(int $daysToKeep = 90, ?string $snapshotType = 'auto_periodic'): int
    {
        $query = EstimateSnapshot::where('created_at', '<', now()->subDays($daysToKeep));

        if ($snapshotType) {
            $query->where('snapshot_type', $snapshotType);
        }

        return $query->delete();
    }

    public function cleanupExpiredComparisons(): int
    {
        return EstimateComparisonCache::cleanupExpired();
    }

    protected function prepareSnapshotData(Estimate $estimate): array
    {
        $estimate->load(['items', 'sections']);

        return [
            'estimate' => $estimate->toArray(),
            'items' => $estimate->items->toArray(),
            'sections' => $estimate->sections->toArray(),
            'metadata' => [
                'created_at' => now()->toIso8601String(),
                'created_by' => Auth::id(),
            ],
        ];
    }

    protected function performComparison(Estimate $estimate1, Estimate $estimate2): array
    {
        $estimate1->load('items');
        $estimate2->load('items');

        $diff = [
            'estimate_info' => $this->compareEstimateInfo($estimate1, $estimate2),
            'items' => $this->compareItems($estimate1->items, $estimate2->items),
            'summary' => [
                'total_amount_diff' => $estimate2->total_amount - $estimate1->total_amount,
                'items_added' => 0,
                'items_removed' => 0,
                'items_modified' => 0,
            ],
        ];

        return $diff;
    }

    protected function compareEstimateInfo(Estimate $estimate1, Estimate $estimate2): array
    {
        $fields = ['name', 'total_direct_costs', 'total_overhead_costs', 'total_estimated_profit', 'total_amount', 'total_amount_with_vat'];
        $changes = [];

        foreach ($fields as $field) {
            if ($estimate1->$field != $estimate2->$field) {
                $changes[$field] = [
                    'old' => $estimate1->$field,
                    'new' => $estimate2->$field,
                ];
            }
        }

        return $changes;
    }

    protected function compareItems(Collection $items1, Collection $items2): array
    {
        $items1Map = $items1->keyBy('position_number');
        $items2Map = $items2->keyBy('position_number');

        $added = [];
        $removed = [];
        $modified = [];

        foreach ($items2Map as $position => $item) {
            if (!$items1Map->has($position)) {
                $added[] = $item->toArray();
            } else {
                $oldItem = $items1Map->get($position);
                if ($this->itemsAreDifferent($oldItem, $item)) {
                    $modified[] = [
                        'position' => $position,
                        'old' => $oldItem->toArray(),
                        'new' => $item->toArray(),
                    ];
                }
            }
        }

        foreach ($items1Map as $position => $item) {
            if (!$items2Map->has($position)) {
                $removed[] = $item->toArray();
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ];
    }

    protected function itemsAreDifferent($item1, $item2): bool
    {
        $fields = ['name', 'quantity', 'unit_price', 'total_amount'];

        foreach ($fields as $field) {
            if ($item1->$field != $item2->$field) {
                return true;
            }
        }

        return false;
    }

    protected function compareData(array $data1, array $data2): array
    {
        return [
            'estimate' => array_diff_assoc($data2['estimate'] ?? [], $data1['estimate'] ?? []),
            'items_diff' => $this->arrayDiff($data1['items'] ?? [], $data2['items'] ?? []),
        ];
    }

    protected function arrayDiff(array $arr1, array $arr2): array
    {
        return [
            'added' => array_diff_key($arr2, $arr1),
            'removed' => array_diff_key($arr1, $arr2),
            'modified' => array_intersect_key($arr1, $arr2),
        ];
    }

    protected function generateComparisonSummary(array $diff): array
    {
        return [
            'estimate_fields_changed' => count($diff['estimate_info'] ?? []),
            'items_added' => count($diff['items']['added'] ?? []),
            'items_removed' => count($diff['items']['removed'] ?? []),
            'items_modified' => count($diff['items']['modified'] ?? []),
            'has_significant_changes' => $this->hasSignificantChanges($diff),
        ];
    }

    protected function hasSignificantChanges(array $diff): bool
    {
        return !empty($diff['estimate_info']) ||
               !empty($diff['items']['added']) ||
               !empty($diff['items']['removed']) ||
               !empty($diff['items']['modified']);
    }
}

