<?php

namespace App\Repositories;

use App\Models\EstimateItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EstimateItemRepository
{
    public function find(int $id): ?EstimateItem
    {
        return EstimateItem::with(['estimate', 'section', 'workType', 'measurementUnit', 'resources'])
            ->find($id);
    }

    public function findOrFail(int $id): EstimateItem
    {
        return EstimateItem::with(['estimate', 'section', 'workType', 'measurementUnit', 'resources'])
            ->findOrFail($id);
    }

    public function create(array $data): EstimateItem
    {
        return EstimateItem::create($data);
    }

    public function update(EstimateItem $item, array $data): bool
    {
        return $item->update($data);
    }

    public function delete(EstimateItem $item): bool
    {
        return $item->delete();
    }

    public function getByEstimate(int $estimateId, int $perPage = 50): LengthAwarePaginator
    {
        return EstimateItem::with(['section', 'workType', 'measurementUnit'])
            ->where('estimate_id', $estimateId)
            ->orderBy('position_number')
            ->paginate($perPage);
    }

    public function getAllByEstimate(int $estimateId): Collection
    {
        return EstimateItem::with(['section', 'workType', 'measurementUnit', 'resources'])
            ->where('estimate_id', $estimateId)
            ->orderBy('position_number')
            ->get();
    }

    public function getBySection(int $sectionId): Collection
    {
        return EstimateItem::with(['workType', 'measurementUnit', 'resources'])
            ->where('estimate_section_id', $sectionId)
            ->orderBy('position_number')
            ->get();
    }

    public function bulkCreate(array $items): Collection
    {
        $createdItems = collect();

        foreach ($items as $itemData) {
            $createdItems->push($this->create($itemData));
        }

        return $createdItems;
    }

    public function moveToSection(EstimateItem $item, int $newSectionId): bool
    {
        return $item->update(['estimate_section_id' => $newSectionId]);
    }

    public function getTotalAmountByEstimate(int $estimateId): float
    {
        return (float) EstimateItem::where('estimate_id', $estimateId)->sum('total_amount');
    }

    public function getTotalAmountBySection(int $sectionId): float
    {
        return (float) EstimateItem::where('estimate_section_id', $sectionId)->sum('total_amount');
    }

    public function countByEstimate(int $estimateId): int
    {
        return EstimateItem::where('estimate_id', $estimateId)->count();
    }

    public function getNextPositionNumber(int $estimateId): string
    {
        $lastItem = EstimateItem::where('estimate_id', $estimateId)
            ->orderByRaw('CAST(position_number AS UNSIGNED) DESC')
            ->first();

        if (!$lastItem) {
            return '1';
        }

        $lastNumber = (int) $lastItem->position_number;
        return (string) ($lastNumber + 1);
    }
}

