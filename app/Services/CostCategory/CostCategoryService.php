<?php

namespace App\Services\CostCategory;

use App\Models\CostCategory;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CostCategoryService
{
    /**
     * Получить категории затрат для текущей организации с пагинацией.
     *
     * @param Request $request
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getCostCategoriesForCurrentOrg(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $request->user()->current_organization_id;
        
        // Формируем запрос
        $query = CostCategory::with('parent')
            ->where('organization_id', $organizationId);
        
        // Фильтрация по поисковому запросу
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('external_code', 'like', "%{$search}%");
            });
        }
        
        // Фильтрация по активности
        if ($request->has('is_active')) {
            $isActive = $request->get('is_active') === 'true' || $request->get('is_active') === '1';
            $query->where('is_active', $isActive);
        }
        
        // Фильтрация по родительской категории
        if ($request->has('parent_id')) {
            $parentId = $request->get('parent_id');
            if ($parentId === 'null' || $parentId === '0') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }
        
        // Сортировка
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);
        
        // Возвращаем пагинированный результат
        return $query->paginate($perPage);
    }
    
    /**
     * Создать новую категорию затрат.
     *
     * @param array $data
     * @param Request $request
     * @return CostCategory
     */
    public function createCostCategory(array $data, Request $request): CostCategory
    {
        $organizationId = $request->user()->current_organization_id;
        
        // Проверяем, что если указан parent_id, то он принадлежит той же организации
        if (isset($data['parent_id']) && $data['parent_id']) {
            $parentCategory = CostCategory::where('id', $data['parent_id'])
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$parentCategory) {
                throw new BusinessLogicException('Родительская категория не найдена или принадлежит другой организации.', 400);
            }
        }
        
        // Устанавливаем организацию
        $data['organization_id'] = $organizationId;
        
        // Создаем новую категорию
        try {
            return DB::transaction(function () use ($data) {
                return CostCategory::create($data);
            });
        } catch (\Exception $e) {
            Log::error('Error creating cost category', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw new BusinessLogicException('Ошибка при создании категории затрат: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Найти категорию затрат по ID для текущей организации.
     *
     * @param int $id
     * @param Request $request
     * @return CostCategory|null
     */
    public function findCostCategoryByIdForCurrentOrg(int $id, Request $request): ?CostCategory
    {
        $organizationId = $request->user()->current_organization_id;
        
        return CostCategory::with(['parent', 'children'])
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();
    }
    
    /**
     * Обновить категорию затрат.
     *
     * @param int $id
     * @param array $data
     * @param Request $request
     * @return CostCategory|null
     */
    public function updateCostCategory(int $id, array $data, Request $request): ?CostCategory
    {
        $organizationId = $request->user()->current_organization_id;
        
        $costCategory = CostCategory::where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();
            
        if (!$costCategory) {
            return null;
        }
        
        // Проверяем, что если указан parent_id, то он принадлежит той же организации
        if (isset($data['parent_id']) && $data['parent_id']) {
            // Не позволяем категории быть родителем самой себя
            if ($data['parent_id'] == $id) {
                throw new BusinessLogicException('Категория не может быть родителем самой себя.', 400);
            }
            
            $parentCategory = CostCategory::where('id', $data['parent_id'])
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$parentCategory) {
                throw new BusinessLogicException('Родительская категория не найдена или принадлежит другой организации.', 400);
            }
        }
        
        try {
            return DB::transaction(function () use ($costCategory, $data) {
                $costCategory->update($data);
                return $costCategory->refresh();
            });
        } catch (\Exception $e) {
            Log::error('Error updating cost category', [
                'id' => $id,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw new BusinessLogicException('Ошибка при обновлении категории затрат: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Удалить категорию затрат.
     *
     * @param int $id
     * @param Request $request
     * @return bool
     */
    public function deleteCostCategory(int $id, Request $request): bool
    {
        $organizationId = $request->user()->current_organization_id;
        
        $costCategory = CostCategory::where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();
            
        if (!$costCategory) {
            return false;
        }
        
        // Проверяем, есть ли дочерние категории
        $hasChildren = CostCategory::where('parent_id', $id)->exists();
        if ($hasChildren) {
            throw new BusinessLogicException('Нельзя удалить категорию, у которой есть дочерние категории.', 400);
        }
        
        // Проверяем, есть ли проекты, связанные с этой категорией
        $hasProjects = $costCategory->projects()->exists();
        if ($hasProjects) {
            throw new BusinessLogicException('Нельзя удалить категорию, к которой привязаны проекты.', 400);
        }
        
        try {
            return DB::transaction(function () use ($costCategory) {
                return (bool) $costCategory->delete();
            });
        } catch (\Exception $e) {
            Log::error('Error deleting cost category', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException('Ошибка при удалении категории затрат: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Импортировать категории затрат из внешней системы.
     *
     * @param array $categories
     * @param int $organizationId
     * @return array
     */
    public function importCostCategories(array $categories, int $organizationId): array
    {
        $imported = 0;
        $updated = 0;
        $errors = [];
        
        try {
            DB::beginTransaction();
            
            foreach ($categories as $index => $categoryData) {
                try {
                    // Проверяем наличие обязательных полей
                    if (empty($categoryData['name'])) {
                        $errors[] = "Строка {$index}: отсутствует обязательное поле 'name'";
                        continue;
                    }
                    
                    // Ищем существующую категорию по external_code
                    $existingCategory = null;
                    if (!empty($categoryData['external_code'])) {
                        $existingCategory = CostCategory::where('organization_id', $organizationId)
                            ->where('external_code', $categoryData['external_code'])
                            ->first();
                    }
                    
                    // Подготавливаем данные для создания/обновления
                    $data = [
                        'name' => $categoryData['name'],
                        'code' => $categoryData['code'] ?? null,
                        'external_code' => $categoryData['external_code'] ?? null,
                        'description' => $categoryData['description'] ?? null,
                        'organization_id' => $organizationId,
                        'is_active' => $categoryData['is_active'] ?? true,
                        'sort_order' => $categoryData['sort_order'] ?? 0,
                        'additional_attributes' => isset($categoryData['additional_attributes']) 
                            ? json_decode($categoryData['additional_attributes'], true) 
                            : null
                    ];
                    
                    if ($existingCategory) {
                        // Обновляем существующую категорию
                        $existingCategory->update($data);
                        $updated++;
                    } else {
                        // Создаем новую категорию
                        CostCategory::create($data);
                        $imported++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Строка {$index}: " . $e->getMessage();
                }
            }
            
            // Второй проход для установки родительских категорий
            foreach ($categories as $index => $categoryData) {
                if (!empty($categoryData['external_code']) && !empty($categoryData['parent_external_code'])) {
                    try {
                        $category = CostCategory::where('organization_id', $organizationId)
                            ->where('external_code', $categoryData['external_code'])
                            ->first();
                            
                        $parentCategory = CostCategory::where('organization_id', $organizationId)
                            ->where('external_code', $categoryData['parent_external_code'])
                            ->first();
                            
                        if ($category && $parentCategory) {
                            $category->parent_id = $parentCategory->id;
                            $category->save();
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Строка {$index} (установка родителя): " . $e->getMessage();
                    }
                }
            }
            
            DB::commit();
            
            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importing cost categories', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка при импорте категорий затрат: ' . $e->getMessage(),
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors
            ];
        }
    }
} 