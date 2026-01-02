<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionNumberingService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateSectionResource;
use App\Models\Estimate;
use App\Models\EstimateSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EstimateSectionController extends Controller
{
    public function __construct(
        protected EstimateSectionService $sectionService,
        protected EstimateSectionNumberingService $numberingService
    ) {}

    public function index(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        $sections = $estimateModel->sections()
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
        
        return response()->json([
            'data' => EstimateSectionResource::collection($sections)
        ]);
    }

    public function store(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'section_number' => 'nullable|string|max:50', // ОПЦИОНАЛЬНЫЙ - генерируется автоматически, если не указан
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_summary' => 'nullable|boolean',
        ]);
        
        $validated['estimate_id'] = $estimateModel->id;
        
        $section = $this->sectionService->createSection($validated);
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно создан'
        ], 201);
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
        
        return response()->json([
            'data' => new EstimateSectionResource($section)
        ]);
    }

    public function update(Request $request, EstimateSection $section): JsonResponse
    {
        $this->authorize('update', $section->estimate);
        
        $validated = $request->validate([
            'parent_section_id' => 'nullable|exists:estimate_sections,id',
            'section_number' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'sometimes|integer',
            'is_summary' => 'sometimes|boolean',
        ]);
        
        $section = $this->sectionService->updateSection($section, $validated);
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно обновлен'
        ]);
    }

    public function destroy(Request $request, EstimateSection $section): JsonResponse
    {
        Log::info('EstimateSectionController::destroy called', [
            'section_id' => $section->id,
            'estimate_id' => $section->estimate_id,
            'estimate_loaded' => $section->relationLoaded('estimate'),
            'estimate_exists' => $section->estimate ? true : false
        ]);

        // Убеждаемся, что estimate загружен
        if (!$section->relationLoaded('estimate')) {
            $section->load('estimate');
        }
        
        if (!$section->estimate) {
            // Если смета не найдена (например, удалена), но раздел существует,
            // мы все равно должны позволить удалить раздел (для очистки мусора),
            // если у пользователя есть права на уровне системы или организации.
            // Но authorize('update', null) не сработает.
            
            Log::warning('EstimateSectionController::destroy - Estimate not found for section', [
                'section_id' => $section->id
            ]);
            
            // Временное решение: удаляем без проверки прав сметы, но с проверкой прав администратора
            // Или просто удаляем, так как это "сирота"
            // Но лучше вернуть ошибку, если это не ожидаемое поведение
            
            // Пробуем найти смету даже если она удалена (если есть SoftDeletes)
            $estimateWithTrashed = Estimate::withTrashed()->find($section->estimate_id);
            if ($estimateWithTrashed) {
                 Log::info('Found trashed estimate', ['id' => $estimateWithTrashed->id]);
                 $this->authorize('update', $estimateWithTrashed);
            } else {
                 // Совсем нет сметы. Проверяем глобальное право?
                 // Пока оставим 404, но с логом
                 abort(404, 'Смета раздела не найдена');
            }
        } else {
            $this->authorize('update', $section->estimate);
        }
        
        $cascade = $request->boolean('cascade', false);
        
        $this->sectionService->deleteSection($section, $cascade);
        
        return response()->json([
            'message' => 'Раздел успешно удален'
        ]);
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
        
        return response()->json([
            'data' => new EstimateSectionResource($section),
            'message' => 'Раздел успешно перемещен'
        ]);
    }

    /**
     * Массовое обновление порядка разделов (для drag-and-drop)
     * 
     * @param Request $request
     * @param int $project ID проекта
     * @param int $estimate ID сметы
     * @return JsonResponse
     * 
     * Формат входных данных:
     * {
     *   "sections": [
     *     {"id": 1, "sort_order": 0, "parent_section_id": null},
     *     {"id": 2, "sort_order": 1, "parent_section_id": null},
     *     {"id": 3, "sort_order": 0, "parent_section_id": 1},
     *   ]
     * }
     */
    public function reorder(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $validated = $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|exists:estimate_sections,id',
            'sections.*.sort_order' => 'required|integer|min:0',
            'sections.*.parent_section_id' => 'nullable|exists:estimate_sections,id',
        ]);

        try {
            // Обновляем порядок и родителей для всех разделов
            foreach ($validated['sections'] as $sectionData) {
                $section = EstimateSection::find($sectionData['id']);
                
                // Проверяем, что раздел принадлежит данной смете
                if ($section->estimate_id !== $estimateModel->id) {
                    return response()->json([
                        'success' => false,
                        'error' => "Раздел {$sectionData['id']} не принадлежит данной смете"
                    ], 422);
                }
                
                $section->update([
                    'sort_order' => $sectionData['sort_order'],
                    'parent_section_id' => $sectionData['parent_section_id'] ?? null,
                ]);
            }

            // Пересчитываем номера всех разделов после изменения порядка
            $this->numberingService->recalculateAllSectionNumbers($estimateModel->id);

            // Возвращаем обновленную иерархию разделов
            $sections = $estimateModel->sections()
                ->with([
                    'children.children.children.children',
                    'items',
                    'children.items',
                    'children.children.items',
                    'children.children.children.items',
                ])
                ->whereNull('parent_section_id')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Порядок разделов успешно обновлен',
                'data' => EstimateSectionResource::collection($sections)
            ]);
        } catch (\Exception $e) {
            \Log::error('estimate.sections.reorder.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить порядок разделов'
            ], 500);
        }
    }

    /**
     * Пересчитать номера всех разделов сметы вручную
     * Полезно для нормализации после импорта или исправления ошибок
     */
    public function recalculateNumbers(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);

        try {
            $this->numberingService->recalculateAllSectionNumbers($estimateModel->id);

            return response()->json([
                'success' => true,
                'message' => 'Нумерация разделов успешно пересчитана'
            ]);
        } catch (\Exception $e) {
            \Log::error('estimate.sections.recalculate_numbers.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось пересчитать нумерацию'
            ], 500);
        }
    }

    /**
     * Валидация корректности нумерации разделов
     * Возвращает список ошибок, если они есть
     */
    public function validateNumbering(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);

        try {
            $errors = $this->numberingService->validateNumbering($estimateModel->id);

            return response()->json([
                'success' => true,
                'is_valid' => empty($errors),
                'errors' => $errors,
                'message' => empty($errors) 
                    ? 'Нумерация корректна' 
                    : 'Обнаружены ошибки в нумерации'
            ]);
        } catch (\Exception $e) {
            \Log::error('estimate.sections.validate_numbering.error', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось выполнить валидацию'
            ], 500);
        }
    }
}
