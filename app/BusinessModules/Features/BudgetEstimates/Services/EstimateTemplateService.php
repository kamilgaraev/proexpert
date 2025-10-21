<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Repositories\EstimateTemplateRepository;
use App\Repositories\EstimateSectionRepository;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Facades\DB;

class EstimateTemplateService
{
    public function __construct(
        protected EstimateTemplateRepository $repository,
        protected EstimateSectionRepository $sectionRepository,
        protected EstimateItemRepository $itemRepository,
        protected EstimateService $estimateService,
        protected EstimateSectionService $sectionService
    ) {}

    public function createFromEstimate(Estimate $estimate, string $name, ?string $description = null): EstimateTemplate
    {
        return DB::transaction(function () use ($estimate, $name, $description) {
            $sections = $this->sectionRepository->getByEstimate($estimate->id);
            $items = $this->itemRepository->getAllByEstimate($estimate->id);
            
            $templateStructure = [
                'sections' => $sections->map(function ($section) {
                    return [
                        'id' => $section->id,
                        'parent_id' => $section->parent_section_id,
                        'section_number' => $section->section_number,
                        'name' => $section->name,
                        'description' => $section->description,
                        'sort_order' => $section->sort_order,
                        'is_summary' => $section->is_summary,
                    ];
                })->toArray(),
                'items' => $items->map(function ($item) {
                    return [
                        'section_id' => $item->estimate_section_id,
                        'position_number' => $item->position_number,
                        'name' => $item->name,
                        'description' => $item->description,
                        'work_type_id' => $item->work_type_id,
                        'measurement_unit_id' => $item->measurement_unit_id,
                        'default_quantity' => $item->quantity,
                        'default_unit_price' => $item->unit_price,
                        'justification' => $item->justification,
                    ];
                })->toArray(),
                'default_rates' => [
                    'vat_rate' => $estimate->vat_rate,
                    'overhead_rate' => $estimate->overhead_rate,
                    'profit_rate' => $estimate->profit_rate,
                ],
            ];
            
            $template = $this->repository->create([
                'organization_id' => $estimate->organization_id,
                'name' => $name,
                'description' => $description,
                'work_type_category' => $estimate->metadata['work_type_category'] ?? null,
                'template_structure' => $templateStructure,
                'is_public' => false,
                'created_by_user_id' => auth()->id(),
            ]);
            
            return $template;
        });
    }

    public function applyTemplate(EstimateTemplate $template, array $estimateData): Estimate
    {
        return DB::transaction(function () use ($template, $estimateData) {
            $structure = $template->template_structure;
            
            if (isset($structure['default_rates'])) {
                $estimateData = array_merge($structure['default_rates'], $estimateData);
            }
            
            $estimate = $this->estimateService->create($estimateData);
            
            if (isset($structure['sections']) && is_array($structure['sections'])) {
                $createdSections = $this->sectionService->createFromTemplate(
                    $estimate,
                    $structure['sections']
                );
                
                $sectionMapping = collect($createdSections)->keyBy(function ($section, $index) use ($structure) {
                    return $structure['sections'][$index]['id'] ?? $index;
                })->map->id;
                
                if (isset($structure['items']) && is_array($structure['items'])) {
                    foreach ($structure['items'] as $templateItem) {
                        $this->itemRepository->create([
                            'estimate_id' => $estimate->id,
                            'estimate_section_id' => isset($templateItem['section_id']) && isset($sectionMapping[$templateItem['section_id']])
                                ? $sectionMapping[$templateItem['section_id']]
                                : null,
                            'position_number' => $templateItem['position_number'] ?? null,
                            'name' => $templateItem['name'],
                            'description' => $templateItem['description'] ?? null,
                            'work_type_id' => $templateItem['work_type_id'] ?? null,
                            'measurement_unit_id' => $templateItem['measurement_unit_id'] ?? null,
                            'quantity' => $templateItem['default_quantity'] ?? 1,
                            'unit_price' => $templateItem['default_unit_price'] ?? 0,
                            'justification' => $templateItem['justification'] ?? null,
                        ]);
                    }
                }
            }
            
            $this->repository->incrementUsage($template);
            
            return $estimate->fresh(['sections', 'items']);
        });
    }

    public function getTemplates(int $organizationId, bool $includePublic = true)
    {
        return $this->repository->getByOrganization($organizationId, $includePublic);
    }

    public function shareWithHolding(EstimateTemplate $template): EstimateTemplate
    {
        $template->update(['is_public' => true]);
        
        return $template;
    }

    public function delete(EstimateTemplate $template): bool
    {
        return $this->repository->delete($template);
    }
}

