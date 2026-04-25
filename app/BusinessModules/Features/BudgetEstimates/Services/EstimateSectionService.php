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
        if ($newSortOrder === null) {
            $newSortOrder = $this->repository->getNextSortOrder($section->estimate_id, $newParentId);
        }
        
        $this->repository->moveSection($section, $newParentId, $newSortOrder);
        $this->invalidateEstimateStructure((int) $section->estimate_id);
        
        return $section->fresh();
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

