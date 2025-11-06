<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LaborResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер справочника трудовых ресурсов
 */
class LaborResourceController extends Controller
{
    /**
     * Список трудовых ресурсов
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $query = LaborResource::where('organization_id', $organizationId);
            
            // Фильтры
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }
            
            if ($request->filled('profession')) {
                $query->where('profession', $request->input('profession'));
            }
            
            if ($request->filled('skill_level')) {
                $query->where('skill_level', $request->input('skill_level'));
            }
            
            // Поиск
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('code', 'ILIKE', "%{$search}%")
                      ->orWhere('profession', 'ILIKE', "%{$search}%");
                });
            }
            
            // Сортировка
            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);
            
            $perPage = min($request->input('per_page', 15), 100);
            $resources = $query->with('measurementUnit')->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $resources->items(),
                'meta' => [
                    'current_page' => $resources->currentPage(),
                    'per_page' => $resources->perPage(),
                    'total' => $resources->total(),
                    'last_page' => $resources->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('labor_resources.index.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить список трудовых ресурсов',
            ], 500);
        }
    }

    /**
     * Получить ресурс по ID
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $resource = LaborResource::where('organization_id', $organizationId)
                ->with('measurementUnit')
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $resource,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Трудовой ресурс не найден',
            ], 404);
        }
    }

    /**
     * Создать ресурс
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $validated = $request->validate([
                'code' => 'required|string|max:100',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'profession' => 'nullable|string',
                'skill_level' => 'nullable|integer|min:1|max:8',
                'measurement_unit_id' => 'nullable|exists:measurement_units,id',
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'monthly_rate' => 'nullable|numeric',
                'coefficient' => 'nullable|numeric',
                'work_hours_per_shift' => 'nullable|numeric',
                'is_active' => 'nullable|boolean',
            ]);
            
            $exists = LaborResource::where('organization_id', $organizationId)
                ->where('code', $validated['code'])
                ->exists();
            
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'error' => 'Трудовой ресурс с таким кодом уже существует',
                ], 422);
            }
            
            $resource = LaborResource::create(array_merge($validated, [
                'organization_id' => $organizationId,
            ]));
            
            return response()->json([
                'success' => true,
                'message' => 'Трудовой ресурс успешно создан',
                'data' => $resource->load('measurementUnit'),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('labor_resources.store.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать трудовой ресурс',
            ], 500);
        }
    }

    /**
     * Обновить ресурс
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $resource = LaborResource::where('organization_id', $organizationId)->findOrFail($id);
            
            $validated = $request->validate([
                'code' => 'sometimes|string|max:100',
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'profession' => 'nullable|string',
                'skill_level' => 'nullable|integer|min:1|max:8',
                'measurement_unit_id' => 'nullable|exists:measurement_units,id',
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'monthly_rate' => 'nullable|numeric',
                'coefficient' => 'nullable|numeric',
                'work_hours_per_shift' => 'nullable|numeric',
                'is_active' => 'nullable|boolean',
            ]);
            
            if (isset($validated['code']) && $validated['code'] !== $resource->code) {
                $exists = LaborResource::where('organization_id', $organizationId)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $id)
                    ->exists();
                
                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Трудовой ресурс с таким кодом уже существует',
                    ], 422);
                }
            }
            
            $resource->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Трудовой ресурс успешно обновлен',
                'data' => $resource->load('measurementUnit'),
            ]);
        } catch (\Exception $e) {
            \Log::error('labor_resources.update.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить трудовой ресурс',
            ], 500);
        }
    }

    /**
     * Удалить ресурс
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $resource = LaborResource::where('organization_id', $organizationId)->findOrFail($id);
            
            $usedInEstimates = $resource->estimateItems()->count();
            
            if ($usedInEstimates > 0) {
                return response()->json([
                    'success' => false,
                    'error' => "Трудовой ресурс используется в {$usedInEstimates} позициях смет и не может быть удален",
                ], 422);
            }
            
            $resource->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Трудовой ресурс успешно удален',
            ]);
        } catch (\Exception $e) {
            \Log::error('labor_resources.destroy.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить трудовой ресурс',
            ], 500);
        }
    }

    /**
     * Получить список профессий
     */
    public function professions(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $professions = LaborResource::where('organization_id', $organizationId)
            ->whereNotNull('profession')
            ->distinct('profession')
            ->pluck('profession');
        
        return response()->json([
            'success' => true,
            'data' => $professions,
        ]);
    }

    /**
     * Получить список категорий
     */
    public function categories(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $categories = LaborResource::where('organization_id', $organizationId)
            ->whereNotNull('category')
            ->distinct('category')
            ->pluck('category');
        
        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Статистика
     */
    public function statistics(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $total = LaborResource::where('organization_id', $organizationId)->count();
        $active = LaborResource::where('organization_id', $organizationId)->active()->count();
        
        $byCategory = LaborResource::where('organization_id', $organizationId)
            ->selectRaw('category, COUNT(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('count', 'category');
        
        $bySkillLevel = LaborResource::where('organization_id', $organizationId)
            ->selectRaw('skill_level, COUNT(*) as count')
            ->whereNotNull('skill_level')
            ->groupBy('skill_level')
            ->pluck('count', 'skill_level');
        
        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'by_category' => $byCategory,
                'by_skill_level' => $bySkillLevel,
            ],
        ]);
    }
}

