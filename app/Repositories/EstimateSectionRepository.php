<?php

namespace App\Repositories;

use App\Models\EstimateSection;
use Illuminate\Database\Eloquent\Collection;

class EstimateSectionRepository
{
    public function find(int $id): ?EstimateSection
    {
        return EstimateSection::with(['estimate', 'parent', 'children', 'items'])->find($id);
    }

    public function findOrFail(int $id): EstimateSection
    {
        return EstimateSection::with(['estimate', 'parent', 'children', 'items'])->findOrFail($id);
    }

    public function create(array $data): EstimateSection
    {
        return EstimateSection::create($data);
    }

    public function update(EstimateSection $section, array $data): bool
    {
        return $section->update($data);
    }

    public function delete(EstimateSection $section): bool
    {
        return $section->delete();
    }

    public function getByEstimate(int $estimateId): Collection
    {
        return EstimateSection::with(['children', 'items'])
            ->where('estimate_id', $estimateId)
            ->orderBy('sort_order')
            ->get();
    }

    public function getRootSections(int $estimateId): Collection
    {
        return EstimateSection::with(['children', 'items'])
            ->where('estimate_id', $estimateId)
            ->whereNull('parent_section_id')
            ->orderBy('sort_order')
            ->get();
    }

    public function getHierarchy(int $estimateId): Collection
    {
        return $this->getRootSections($estimateId)->map(function ($section) {
            return $this->buildSectionTree($section);
        });
    }

    protected function buildSectionTree(EstimateSection $section): array
    {
        $data = $section->toArray();
        $data['children'] = $section->children->map(function ($child) {
            return $this->buildSectionTree($child);
        })->toArray();
        
        return $data;
    }

    public function getNextSortOrder(int $estimateId, ?int $parentSectionId = null): int
    {
        $query = EstimateSection::where('estimate_id', $estimateId);

        if ($parentSectionId) {
            $query->where('parent_section_id', $parentSectionId);
        } else {
            $query->whereNull('parent_section_id');
        }

        $maxOrder = $query->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    public function moveSection(EstimateSection $section, ?int $newParentId, int $newSortOrder): bool
    {
        return $section->update([
            'parent_section_id' => $newParentId,
            'sort_order' => $newSortOrder,
        ]);
    }

    public function updateSortOrders(array $sectionsWithOrders): void
    {
        foreach ($sectionsWithOrders as $sectionId => $sortOrder) {
            EstimateSection::where('id', $sectionId)->update(['sort_order' => $sortOrder]);
        }
    }
}

