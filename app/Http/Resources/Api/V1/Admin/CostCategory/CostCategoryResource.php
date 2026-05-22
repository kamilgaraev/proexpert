<?php

namespace App\Http\Resources\Api\V1\Admin\CostCategory;

use App\Http\Resources\ModelJsonResource;
use App\Models\CostCategory;
use Illuminate\Http\Request;

class CostCategoryResource extends ModelJsonResource
{
    /**
     * Преобразовать ресурс в массив.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->typedResource(CostCategory::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'external_code' => $this->external_code,
            'description' => $this->description,
            'organization_id' => $this->organization_id,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'additional_attributes' => $this->additional_attributes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Включаем связанные данные, если они загружены
            'parent' => $this->when($category->relationLoaded('parent'), function () {
                return [
                    'id' => $this->parent->id ?? null,
                    'name' => $this->parent->name ?? null,
                    'code' => $this->parent->code ?? null,
                ];
            }),
            
            'children' => $this->when($category->relationLoaded('children'), function () {
                return self::collection($this->children);
            }),
            
            // Количество проектов, связанных с категорией
            'projects_count' => $this->when($category->relationLoaded('projects'), function () use ($category) {
                 // Добавим проверку на существование связи перед вызовом count()
                 return $category->projects->count();
            }),
            
            // Путь категории (для иерархических отображений)
            'path' => $this->when($category->relationLoaded('parent'), function () use ($category) {
                $path = [];
                $currentCategory = $category;

                while ($currentCategory && $currentCategory->relationLoaded('parent') && $currentCategory->parent) {
                    if (!$currentCategory->parent->id) {
                        break;
                    }

                    array_unshift($path, [
                        'id' => $currentCategory->parent->id,
                        'name' => $currentCategory->parent->name,
                    ]);

                    if ($currentCategory->parent->id === $currentCategory->id) {
                        break;
                    }

                    $currentCategory = $currentCategory->parent;
                }

                return $path;
            }),
        ];
    }
}
