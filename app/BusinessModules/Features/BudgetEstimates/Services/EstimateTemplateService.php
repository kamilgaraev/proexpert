<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Policies\EstimatePolicy;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Project\UserProjectAccessService;
use App\Repositories\EstimateTemplateRepository;
use App\Repositories\EstimateSectionRepository;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Builder;

class EstimateTemplateService
{
    public function __construct(
        protected EstimateTemplateRepository $repository,
        protected EstimateSectionRepository $sectionRepository,
        protected EstimateItemRepository $itemRepository,
        protected EstimateService $estimateService,
        protected EstimateSectionService $sectionService,
        protected EstimatePolicy $estimatePolicy,
        protected UserProjectAccessService $projectAccessService,
    ) {}

    public function createFromEstimate(Estimate $estimate, string $name, User $actor, ?string $description = null): EstimateTemplate
    {
        return DB::transaction(function () use ($estimate, $name, $actor, $description) {
            $estimate = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            $organizationId = $this->assertCanManageTemplates($actor, (int) $estimate->organization_id);
            if (! $this->estimatePolicy->view($actor, $estimate)) {
                throw new AuthorizationException(trans_message('permissions.access_denied'));
            }

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
            
            return $this->repository->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'description' => $description,
                'work_type_category' => $estimate->metadata['work_type_category'] ?? null,
                'template_structure' => $templateStructure,
                'is_public' => false,
                'created_by_user_id' => $actor->id,
            ]);
        });
    }

    public function applyTemplate(EstimateTemplate $template, array $estimateData, User $actor): Estimate
    {
        return DB::transaction(function () use ($template, $estimateData, $actor) {
            $organizationId = $this->activeOrganizationId($actor);
            $template = EstimateTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $project = Project::query()->useWritePdo()->whereKey((int) ($estimateData['project_id'] ?? 0))->first();
            if (
                (! $template->is_public && (int) $template->organization_id !== $organizationId)
                || ! $this->estimatePolicy->create($actor)
                || ! $project
                || ! $this->projectAccessService->canAccessCurrentOrganizationProject($actor, $project)
            ) {
                throw new AuthorizationException(trans_message('permissions.access_denied'));
            }

            $structure = $template->template_structure;
            
            if (isset($structure['default_rates'])) {
                $estimateData = array_merge(Arr::only($structure['default_rates'], [
                    'vat_rate', 'overhead_rate', 'profit_rate',
                ]), $estimateData);
            }
            $estimateData['organization_id'] = $organizationId;
            $estimateData['project_id'] = $project->id;
            $estimateData['type'] ??= 'local';
            
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
                    foreach ($this->scopeCatalogReferences($structure['items'], $organizationId) as $templateItem) {
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

    public function shareWithHolding(EstimateTemplate $template, User $actor): EstimateTemplate
    {
        return DB::transaction(function () use ($template, $actor) {
            $template = EstimateTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManageTemplates($actor, (int) $template->organization_id);
            $this->repository->update($template, ['is_public' => true]);

            return $template;
        });
    }

    public function delete(EstimateTemplate $template, User $actor): bool
    {
        return DB::transaction(function () use ($template, $actor) {
            $template = EstimateTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManageTemplates($actor, (int) $template->organization_id);

            return $this->repository->delete($template);
        });
    }

    private function scopeCatalogReferences(array $items, int $organizationId): array
    {
        $workTypeIds = WorkType::query()->where('organization_id', $organizationId)
            ->whereIn('id', array_column($items, 'work_type_id'))->pluck('id')->flip()->all();
        $unitIds = MeasurementUnit::query()
            ->where(static function (Builder $query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhere('is_system', true);
            })
            ->whereIn('id', array_column($items, 'measurement_unit_id'))->pluck('id')->flip()->all();

        return array_map(static function (array $item) use ($workTypeIds, $unitIds): array {
            $workTypeId = (int) ($item['work_type_id'] ?? 0);
            $unitId = (int) ($item['measurement_unit_id'] ?? 0);
            $item['work_type_id'] = isset($workTypeIds[$workTypeId]) ? $workTypeId : null;
            $item['measurement_unit_id'] = isset($unitIds[$unitId]) ? $unitId : null;

            return $item;
        }, $items);
    }

    private function assertCanManageTemplates(User $actor, int $ownerOrganizationId): int
    {
        $organizationId = $this->activeOrganizationId($actor);
        if ($ownerOrganizationId !== $organizationId || ! $this->estimatePolicy->manageTemplates($actor)) {
            throw new AuthorizationException(trans_message('permissions.access_denied'));
        }

        return $organizationId;
    }

    private function activeOrganizationId(User $actor): int
    {
        $organizationId = (int) $actor->current_organization_id;
        if (
            $organizationId <= 0
            || ! $actor->organizations()->useWritePdo()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists()
        ) {
            throw new AuthorizationException(trans_message('permissions.access_denied'));
        }

        return $organizationId;
    }
}

