<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Repositories\EstimateSectionRepository;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Facades\DB;

class EstimateSectionService
{
    public function __construct(
        protected EstimateSectionRepository $repository,
        protected EstimateItemRepository $itemRepository,
        protected EstimateCalculationService $calculationService,
        protected EstimateCacheService $cacheService
    ) {}

    public function createSection(array $data): EstimateSection
    {
        // Проверка лимита разделов
        $this->checkSectionsLimit($data['estimate_id']);
        
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = $this->repository->getNextSortOrder(
                $data['estimate_id'],
                $data['parent_section_id'] ?? null
            );
        }
        
        $section = $this->repository->create($data);
        $this->invalidateEstimateStructure((int) $section->estimate_id);

        return $section;
    }
    
    /**
     * Проверить лимит разделов в смете
     */
    private function checkSectionsLimit(int $estimateId): void
    {
        $module = app(\App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule::class);
        $limits = $module->getLimits();
        
        $currentCount = EstimateSection::where('estimate_id', $estimateId)->count();
        $maxSections = $limits['max_sections_per_estimate'];
        
        if ($maxSections && $currentCount >= $maxSections) {
            throw new \DomainException("Достигнут лимит разделов в смете: {$maxSections}");
        }
    }

    public function updateSection(EstimateSection $section, array $data): EstimateSection
    {
        $this->repository->update($section, $data);
        $this->invalidateEstimateStructure((int) $section->estimate_id);
        
        return $section->fresh();
    }

    public function deleteSection(EstimateSection $section, bool $cascade = false): bool
    {
        return DB::transaction(function () use ($section, $cascade) {
            $estimateId = (int) $section->estimate_id;

            if ($cascade) {
                foreach ($section->children as $child) {
                    $this->deleteSection($child, true);
                }
                
                $section->items()->delete();
            } else {
                $section->children()->update(['parent_section_id' => $section->parent_section_id]);
                $section->items()->update(['estimate_section_id' => $section->parent_section_id]);
            }
            
            $deleted = $this->repository->delete($section);
            $this->invalidateEstimateStructure($estimateId);

            return $deleted;
        });
    }

    public function moveSection(EstimateSection $section, ?int $newParentId, ?int $newSortOrder = null): EstimateSection
    {
        $estimateId = (int) $section->estimate_id;

        DB::transaction(function () use ($section, $estimateId, $newParentId, $newSortOrder): void {
            Estimate::whereKey($estimateId)->lockForUpdate()->firstOrFail();
            $sections = EstimateSection::where('estimate_id', $estimateId)
                ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $moving = $sections->get($section->id);

            if (!$moving || ($newParentId !== null && !$sections->has($newParentId))) {
                throw new \DomainException(trans_message('estimate.section_not_belongs_to_estimate'));
            }

            $oldParentId = $moving->parent_section_id;
            $moving->parent_section_id = $newParentId;
            $this->validateSectionStructure($sections->all());

            $siblings = $sections->filter(fn (EstimateSection $candidate): bool =>
                $candidate->id !== $moving->id && $candidate->parent_section_id === $newParentId
            )->values()->all();
            $position = $newSortOrder === null ? count($siblings) : max(0, min($newSortOrder, count($siblings)));
            array_splice($siblings, $position, 0, [$moving]);

            foreach ($siblings as $index => $sibling) {
                $sibling->sort_order = $index;
            }

            if ($oldParentId !== $newParentId) {
                $oldSiblings = $sections->filter(fn (EstimateSection $candidate): bool =>
                    $candidate->parent_section_id === $oldParentId
                )->values();
                foreach ($oldSiblings as $index => $sibling) {
                    $sibling->sort_order = $index;
                }
            }

            $this->persistSectionStructure($estimateId, $sections->all());
        });

        $this->invalidateEstimateStructure($estimateId);

        return $section->fresh();
    }

    public function reorderSections(Estimate $estimate, array $changes): void
    {
        DB::transaction(function () use ($estimate, $changes): void {
            Estimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            $sections = EstimateSection::where('estimate_id', $estimate->id)
                ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($changes as $change) {
                $section = $sections->get($change['id']);
                $parentId = $change['parent_section_id'] ?? null;
                if (!$section || ($parentId !== null && !$sections->has($parentId))) {
                    throw new \DomainException(trans_message('estimate.section_not_belongs_to_estimate'));
                }
                $section->parent_section_id = $parentId;
                $section->sort_order = $change['sort_order'];
            }

            $this->validateSectionStructure($sections->all());
            foreach ($sections->groupBy(fn (EstimateSection $section) => $section->parent_section_id ?? 'root') as $siblings) {
                foreach ($siblings->sortBy('sort_order')->values() as $index => $section) {
                    $section->sort_order = $index;
                }
            }
            $this->persistSectionStructure((int) $estimate->id, $sections->all());
        });

        $this->invalidateEstimateStructure((int) $estimate->id);
    }

    private function validateSectionStructure(array $sections): void
    {
        foreach ($sections as $section) {
            $seen = [];
            $current = $section;
            $depth = 0;
            while ($current !== null) {
                if (isset($seen[$current->id])) {
                    $key = $section->parent_section_id === $section->id
                        ? 'estimate.section_parent_self_forbidden'
                        : 'estimate.section_parent_descendant_forbidden';
                    throw new \DomainException(trans_message($key));
                }
                $seen[$current->id] = true;
                $depth++;
                if ($depth > 5) {
                    throw new \DomainException(trans_message('estimate.section_depth_exceeded'));
                }
                $parentId = $current->parent_section_id;
                if ($parentId !== null && !isset($sections[$parentId])) {
                    throw new \DomainException(trans_message('estimate.section_not_belongs_to_estimate'));
                }
                $current = $parentId === null ? null : $sections[$parentId];
            }
        }
    }

    private function persistSectionStructure(int $estimateId, array $sections): void
    {
        foreach ($sections as $section) {
            if ($section->isDirty(['parent_section_id', 'sort_order'])) {
                EstimateSection::where('estimate_id', $estimateId)->whereKey($section->id)->update([
                    'parent_section_id' => $section->parent_section_id,
                    'sort_order' => $section->sort_order,
                ]);
            }
        }

        app(EstimateSectionNumberingService::class)->recalculateAllSectionNumbers($estimateId);
    }

    public function updateSortOrder(array $sectionsWithOrders): void
    {
        $this->repository->updateSortOrders($sectionsWithOrders);

        $estimateId = collect($sectionsWithOrders)
            ->pluck('estimate_id')
            ->filter()
            ->first();

        if ($estimateId) {
            $this->invalidateEstimateStructure((int) $estimateId);
        }
    }

    public function getHierarchy(int $estimateId)
    {
        return $this->repository->getHierarchy($estimateId);
    }

    public function recalculateSectionTotal(EstimateSection $section): float
    {
        return $this->calculationService->calculateSectionTotal($section);
    }

    public function createFromTemplate(Estimate $estimate, array $templateSections): array
    {
        // Проверить лимит для массового создания разделов
        $module = app(\App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule::class);
        $limits = $module->getLimits();
        $maxSections = $limits['max_sections_per_estimate'];
        
        $currentCount = EstimateSection::where('estimate_id', $estimate->id)->count();
        $newSectionsCount = count($templateSections);
        
        if ($maxSections && ($currentCount + $newSectionsCount) > $maxSections) {
            throw new \DomainException(
                "Невозможно применить шаблон. Будет превышен лимит разделов: {$maxSections}. " .
                "Текущее количество: {$currentCount}, добавляется: {$newSectionsCount}"
            );
        }
        
        $createdSections = [];
        $sectionMapping = [];
        
        foreach ($templateSections as $templateSection) {
            $sectionData = [
                'estimate_id' => $estimate->id,
                'parent_section_id' => isset($templateSection['parent_id']) && isset($sectionMapping[$templateSection['parent_id']])
                    ? $sectionMapping[$templateSection['parent_id']]
                    : null,
                'section_number' => $templateSection['section_number'],
                'name' => $templateSection['name'],
                'description' => $templateSection['description'] ?? null,
                'sort_order' => $templateSection['sort_order'] ?? 0,
                'is_summary' => $templateSection['is_summary'] ?? false,
            ];
            
            // createSection уже не будет проверять лимит, так как мы это сделали выше
            if (!isset($sectionData['sort_order'])) {
                $sectionData['sort_order'] = $this->repository->getNextSortOrder(
                    $sectionData['estimate_id'],
                    $sectionData['parent_section_id'] ?? null
                );
            }
            
            $section = $this->repository->create($sectionData);
            $createdSections[] = $section;
            
            if (isset($templateSection['id'])) {
                $sectionMapping[$templateSection['id']] = $section->id;
            }
        }

        $this->cacheService->invalidateStructure($estimate);
        
        return $createdSections;
    }

    private function invalidateEstimateStructure(int $estimateId): void
    {
        $estimate = Estimate::find($estimateId);

        if ($estimate) {
            $this->cacheService->invalidateStructure($estimate);
        }
    }
}

