<?php

declare(strict_types=1);

namespace App\Services\CostCategory;

use App\Exceptions\BusinessLogicException;
use App\Models\CostCategory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CostCategoryService
{
    public function getCostCategoriesForCurrentOrg(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = (int) $request->user()->current_organization_id;

        $query = CostCategory::query()
            ->with('parent')
            ->where('organization_id', $organizationId);

        $search = $request->query('search');
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('external_code', 'like', "%{$search}%");
            });
        }

        if ($request->query('is_active') !== null && $request->query('is_active') !== '') {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $parentId = $request->query('parent_id');
        if ($parentId !== null && $parentId !== '') {
            if ($parentId === 'null' || $parentId === '0') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', (int) $parentId);
            }
        }

        $sortBy = $this->normalizeSortBy((string) $request->query('sort_by', 'sort_order'));
        $sortDirection = $request->query('sort_direction') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sortBy, $sortDirection)->paginate($perPage);
    }

    public function createCostCategory(array $data, Request $request): CostCategory
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $this->assertParentBelongsToOrganization($data['parent_id'] ?? null, $organizationId);

        $data['organization_id'] = $organizationId;

        try {
            return DB::transaction(fn (): CostCategory => CostCategory::query()->create($data));
        } catch (\Throwable $e) {
            Log::error('Error creating cost category', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            throw new BusinessLogicException(trans_message('cost_category.create_failed'), 500);
        }
    }

    public function findCostCategoryByIdForCurrentOrg(int $id, Request $request): ?CostCategory
    {
        return CostCategory::query()
            ->with(['parent', 'children'])
            ->where('id', $id)
            ->where('organization_id', (int) $request->user()->current_organization_id)
            ->first();
    }

    public function updateCostCategory(int $id, array $data, Request $request): ?CostCategory
    {
        $organizationId = (int) $request->user()->current_organization_id;

        $costCategory = CostCategory::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$costCategory) {
            return null;
        }

        if (($data['parent_id'] ?? null) !== null && (int) $data['parent_id'] === $id) {
            throw new BusinessLogicException(trans_message('cost_category.self_parent_forbidden'), 422);
        }

        $this->assertParentBelongsToOrganization($data['parent_id'] ?? null, $organizationId);

        try {
            return DB::transaction(function () use ($costCategory, $data): CostCategory {
                $costCategory->update($data);

                return $costCategory->refresh();
            });
        } catch (\Throwable $e) {
            Log::error('Error updating cost category', [
                'id' => $id,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            throw new BusinessLogicException(trans_message('cost_category.update_failed_business'), 500);
        }
    }

    public function deleteCostCategory(int $id, Request $request): bool
    {
        $organizationId = (int) $request->user()->current_organization_id;

        $costCategory = CostCategory::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$costCategory) {
            return false;
        }

        if (CostCategory::query()->where('organization_id', $organizationId)->where('parent_id', $id)->exists()) {
            throw new BusinessLogicException(trans_message('cost_category.delete_has_children'), 422);
        }

        if ($costCategory->projects()->exists()) {
            throw new BusinessLogicException(trans_message('cost_category.delete_has_projects'), 422);
        }

        try {
            return DB::transaction(fn (): bool => (bool) $costCategory->delete());
        } catch (\Throwable $e) {
            Log::error('Error deleting cost category', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new BusinessLogicException(trans_message('cost_category.delete_failed_business'), 500);
        }
    }

    public function importCostCategories(array $categories, int $organizationId): array
    {
        $imported = 0;
        $updated = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            foreach ($categories as $index => $categoryData) {
                try {
                    if (empty($categoryData['name'])) {
                        $errors[] = "Строка {$index}: отсутствует обязательное поле name";
                        continue;
                    }

                    $existingCategory = null;
                    if (!empty($categoryData['external_code'])) {
                        $existingCategory = CostCategory::query()
                            ->where('organization_id', $organizationId)
                            ->where('external_code', $categoryData['external_code'])
                            ->first();
                    }

                    $data = [
                        'name' => $categoryData['name'],
                        'code' => $categoryData['code'] ?? null,
                        'external_code' => $categoryData['external_code'] ?? null,
                        'description' => $categoryData['description'] ?? null,
                        'organization_id' => $organizationId,
                        'is_active' => $categoryData['is_active'] ?? true,
                        'sort_order' => $categoryData['sort_order'] ?? 0,
                        'additional_attributes' => isset($categoryData['additional_attributes'])
                            ? json_decode((string) $categoryData['additional_attributes'], true)
                            : null,
                    ];

                    if ($existingCategory) {
                        $existingCategory->update($data);
                        $updated++;
                    } else {
                        CostCategory::query()->create($data);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Строка {$index}: {$e->getMessage()}";
                }
            }

            foreach ($categories as $index => $categoryData) {
                if (empty($categoryData['external_code']) || empty($categoryData['parent_external_code'])) {
                    continue;
                }

                try {
                    $category = CostCategory::query()
                        ->where('organization_id', $organizationId)
                        ->where('external_code', $categoryData['external_code'])
                        ->first();
                    $parentCategory = CostCategory::query()
                        ->where('organization_id', $organizationId)
                        ->where('external_code', $categoryData['parent_external_code'])
                        ->first();

                    if ($category && $parentCategory) {
                        $category->parent_id = $parentCategory->id;
                        $category->save();
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Строка {$index} (родительская категория): {$e->getMessage()}";
                }
            }

            DB::commit();

            return [
                'success' => true,
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error importing cost categories', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => trans_message('cost_category.internal_error_import'),
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
            ];
        }
    }

    private function assertParentBelongsToOrganization(mixed $parentId, int $organizationId): void
    {
        if ($parentId === null || $parentId === '') {
            return;
        }

        $exists = CostCategory::query()
            ->where('id', (int) $parentId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new BusinessLogicException(trans_message('cost_category.parent_not_found'), 422);
        }
    }

    private function normalizeSortBy(string $sortBy): string
    {
        return in_array($sortBy, ['name', 'code', 'external_code', 'is_active', 'sort_order', 'created_at'], true)
            ? $sortBy
            : 'sort_order';
    }
}
