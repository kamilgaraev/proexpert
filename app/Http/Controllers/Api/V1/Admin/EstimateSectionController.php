<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\StoreSectionRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\UpdateSectionRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionNumberingService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateSectionResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateSectionController extends Controller
{
    public function __construct(
        protected EstimateSectionService $sectionService,
        protected EstimateSectionNumberingService $numberingService
    ) {}

    public function index(Request $request, $project, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        
        $this->authorize('view', $estimate);
        
        $sections = $estimate->sections()
            ->with([
                'children.children.children.children',
                'items',
                'children.items',
                'children.children.items',
                'children.children.children.items',
                'children.children.children.items',
            ])
            ->whereNull('parent_section_id')
            ->orderBy('sort_order')
            ->get();
        
        return AdminResponse::success(EstimateSectionResource::collection($sections));
    }

    public function store(StoreSectionRequest $request, $project, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        
        $this->authorize('update', $estimate);
        
        $data = $request->validated();
        $data['estimate_id'] = $estimate->id;
        
        $section = $this->sectionService->createSection($data);
        
        return AdminResponse::success(
            new EstimateSectionResource($section),
            trans_message('estimate.section_created'),
            Response::HTTP_CREATED
        );
    }

    public function show(EstimateSection $section): JsonResponse
    {
        $this->authorize('view', $section->estimate);
        
        $section->load([
            'children.children.children.children',
            'items',
            'children.items',
            'children.children.items',
            'children.children.children.items',
        ]);
        
        return AdminResponse::success(new EstimateSectionResource($section));
    }

    public function update(UpdateSectionRequest $request, EstimateSection $section): JsonResponse
    {
        $this->authorize('update', $section->estimate);
        
        $section = $this->sectionService->updateSection($section, $request->validated());
        
        return AdminResponse::success(
            new EstimateSectionResource($section),
            trans_message('estimate.section_updated')
        );
    }

    public function destroy(Request $request, EstimateSection $section): JsonResponse
    {
        // Fallback: ручная загрузка, если Route Model Binding не сработал
        if (!$section->exists) {
            $id = $request->route('section');
            if ($id) {
                $section = EstimateSection::with('estimate')->findOrFail($id);
            }
        }

        // Убеждаемся, что estimate загружен
        if (!$section->relationLoaded('estimate')) {
            $section->load('estimate');
        }
        
        $this->authorizeEstimateAction('update', $section);
        
        $cascade = $request->boolean('cascade', false);
        
        $this->sectionService->deleteSection($section, $cascade);
        
        return AdminResponse::success(null, trans_message('estimate.section_deleted'));
    }

    public function move(Request $request, EstimateSection $section): JsonResponse
    {
        $this->authorize('update', $section->estimate);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'sort_order' => 'nullable|integer',
        ]);
        
        $section = $this->sectionService->moveSection(
            $section,
            $validated['parent_section_id'] ?? null,
            $validated['sort_order'] ?? null
        );
        
        return AdminResponse::success(
            new EstimateSectionResource($section),
            trans_message('estimate.section_moved')
        );
    }

    /**
     * Массовое обновление порядка разделов (для drag-and-drop)
     */
    public function reorder(Request $request, $project, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        
        $this->authorize('update', $estimate);
        
        $validated = $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|exists:estimate_sections,id',
            'sections.*.sort_order' => 'required|integer|min:0',
            'sections.*.parent_section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        try {
            $sectionIds = collect($validated['sections'])->pluck('id')->toArray();
            $sections = EstimateSection::whereIn('id', $sectionIds)->get()->keyBy('id');
            
            // Обновляем порядок и родителей
            foreach ($validated['sections'] as $sectionData) {
                if (!isset($sections[$sectionData['id']])) {
                    continue;
                }
                
                $section = $sections[$sectionData['id']];
                
                // Проверяем принадлежность к смете
                if ($section->estimate_id !== $estimate->id) {
                    return AdminResponse::error(
                        trans_message('estimate.section_not_belongs_to_estimate'),
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
                
                $section->update([
                    'sort_order' => $sectionData['sort_order'],
                    'parent_section_id' => $sectionData['parent_section_id'] ?? null,
                ]);
            }

            // Пересчитываем номера
            $this->numberingService->recalculateAllSectionNumbers($estimate->id);

            // Возвращаем обновленную иерархию
            $updatedSections = $estimate->sections()
                ->with([
                    'children.children.children.children',
                    'items',
                    'children.items',
                    'children.children.items',
                    'children.children.children.items',
                    'children.children.children.items',
                ])
                ->whereNull('parent_section_id')
                ->orderBy('sort_order')
                ->get();

            return AdminResponse::success(
                EstimateSectionResource::collection($updatedSections),
                trans_message('estimate.sections_reordered')
            );
        } catch (\Exception $e) {
            Log::error('estimate.sections.reorder.error', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.sections_reorder_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function recalculateNumbers(Request $request, $project, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        
        $this->authorize('update', $estimate);

        try {
            $this->numberingService->recalculateAllSectionNumbers($estimate->id);

            return AdminResponse::success(null, trans_message('estimate.section_numbering_recalculated'));
        } catch (\Exception $e) {
            Log::error('estimate.sections.recalculate_numbers.error', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.section_numbering_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function validateNumbering(Request $request, $project, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        
        $this->authorize('view', $estimate);

        try {
            $errors = $this->numberingService->validateNumbering($estimate->id);

            return AdminResponse::success(
                [
                    'is_valid' => empty($errors),
                    'errors' => $errors,
                ],
                empty($errors) 
                    ? trans_message('estimate.section_numbering_valid') 
                    : trans_message('estimate.section_numbering_invalid')
            );
        } catch (\Exception $e) {
            Log::error('estimate.sections.validate_numbering.error', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('estimate.section_validation_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Найти смету с проверкой организации
     */
    private function findEstimateOrFail(int $estimateId): Estimate
    {
        $organizationId = request()->attributes->get('current_organization_id');
        
        return Estimate::where('id', $estimateId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
    }

    /**
     * Безопасная проверка прав для раздела с учетом возможного удаления сметы
     */
    private function authorizeEstimateAction(string $ability, EstimateSection $section): void
    {
        if (!$section->estimate) {
            // Пробуем найти смету даже если она удалена (если есть SoftDeletes)
            $estimateWithTrashed = Estimate::withTrashed()->find($section->estimate_id);
            
            if ($estimateWithTrashed) {
                 // Проверяем права на удаленную смету
                 $this->authorize($ability, $estimateWithTrashed);
            } else {
                 // Смета не найдена совсем - возвращаем 404
                 abort(404, 'Смета раздела не найдена');
            }
        } else {
            $this->authorize($ability, $section->estimate);
        }
    }
}
