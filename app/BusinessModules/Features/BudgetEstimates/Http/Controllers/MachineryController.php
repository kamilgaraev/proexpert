<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Machinery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер справочника механизмов
 */
class MachineryController extends Controller
{
    /**
     * Список механизмов с фильтрацией и поиском
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $query = Machinery::where('organization_id', $organizationId);
            
            // Фильтр по активности
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            // Фильтр по категории
            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }
            
            // Поиск
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('code', 'ILIKE', "%{$search}%")
                      ->orWhere('model', 'ILIKE', "%{$search}%");
                });
            }
            
            // Сортировка
            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);
            
            // Пагинация
            $perPage = min($request->input('per_page', 15), 100);
            $machinery = $query->with('measurementUnit')->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $machinery->items(),
                'meta' => [
                    'current_page' => $machinery->currentPage(),
                    'per_page' => $machinery->perPage(),
                    'total' => $machinery->total(),
                    'last_page' => $machinery->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('machinery.index.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить список механизмов',
            ], 500);
        }
    }

    /**
     * Получить механизм по ID
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $machinery = Machinery::where('organization_id', $organizationId)
                ->with('measurementUnit')
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $machinery,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Механизм не найден',
            ], 404);
        }
    }

    /**
     * Создать механизм
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
                'type' => 'nullable|string',
                'measurement_unit_id' => 'nullable|exists:measurement_units,id',
                'model' => 'nullable|string',
                'manufacturer' => 'nullable|string',
                'power' => 'nullable|numeric',
                'capacity' => 'nullable|numeric',
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'fuel_consumption' => 'nullable|numeric',
                'fuel_type' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            
            // Проверка уникальности кода
            $exists = Machinery::where('organization_id', $organizationId)
                ->where('code', $validated['code'])
                ->exists();
            
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'error' => 'Механизм с таким кодом уже существует',
                ], 422);
            }
            
            $machinery = Machinery::create(array_merge($validated, [
                'organization_id' => $organizationId,
            ]));
            
            return response()->json([
                'success' => true,
                'message' => 'Механизм успешно создан',
                'data' => $machinery->load('measurementUnit'),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('machinery.store.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать механизм',
            ], 500);
        }
    }

    /**
     * Обновить механизм
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $machinery = Machinery::where('organization_id', $organizationId)->findOrFail($id);
            
            $validated = $request->validate([
                'code' => 'sometimes|string|max:100',
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'type' => 'nullable|string',
                'measurement_unit_id' => 'nullable|exists:measurement_units,id',
                'model' => 'nullable|string',
                'manufacturer' => 'nullable|string',
                'power' => 'nullable|numeric',
                'capacity' => 'nullable|numeric',
                'hourly_rate' => 'nullable|numeric',
                'shift_rate' => 'nullable|numeric',
                'daily_rate' => 'nullable|numeric',
                'fuel_consumption' => 'nullable|numeric',
                'fuel_type' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            
            // Проверка уникальности кода при изменении
            if (isset($validated['code']) && $validated['code'] !== $machinery->code) {
                $exists = Machinery::where('organization_id', $organizationId)
                    ->where('code', $validated['code'])
                    ->where('id', '!=', $id)
                    ->exists();
                
                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Механизм с таким кодом уже существует',
                    ], 422);
                }
            }
            
            $machinery->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Механизм успешно обновлен',
                'data' => $machinery->load('measurementUnit'),
            ]);
        } catch (\Exception $e) {
            \Log::error('machinery.update.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить механизм',
            ], 500);
        }
    }

    /**
     * Удалить механизм
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $machinery = Machinery::where('organization_id', $organizationId)->findOrFail($id);
            
            // Проверка использования в сметах
            $usedInEstimates = $machinery->estimateItems()->count();
            
            if ($usedInEstimates > 0) {
                return response()->json([
                    'success' => false,
                    'error' => "Механизм используется в {$usedInEstimates} позициях смет и не может быть удален",
                ], 422);
            }
            
            $machinery->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Механизм успешно удален',
            ]);
        } catch (\Exception $e) {
            \Log::error('machinery.destroy.error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить механизм',
            ], 500);
        }
    }

    /**
     * Получить список категорий
     */
    public function categories(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $categories = Machinery::where('organization_id', $organizationId)
            ->whereNotNull('category')
            ->distinct('category')
            ->pluck('category');
        
        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Статистика по механизмам
     */
    public function statistics(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $total = Machinery::where('organization_id', $organizationId)->count();
        $active = Machinery::where('organization_id', $organizationId)->active()->count();
        $byCategory = Machinery::where('organization_id', $organizationId)
            ->selectRaw('category, COUNT(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('count', 'category');
        
        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'by_category' => $byCategory,
            ],
        ]);
    }
}

