<?php

namespace App\Http\Resources\Api\V1\Admin\CostCategory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostCategoryResource extends JsonResource
{
    /**
     * Преобразовать ресурс в массив.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'parent' => $this->when($this->relationLoaded('parent'), function () {
                return [
                    'id' => $this->parent->id ?? null,
                    'name' => $this->parent->name ?? null,
                    'code' => $this->parent->code ?? null,
                ];
            }),
            
            'children' => $this->when($this->relationLoaded('children'), function () {
                return self::collection($this->children);
            }),
            
            // Количество проектов, связанных с категорией
            'projects_count' => $this->when($this->relationLoaded('projects'), function () {
                return $this->projects->count();
            }),
            
            // Путь категории (для иерархических отображений)
            'path' => $this->when($this->relationLoaded('parent'), function () {
                $path = [];
                $category = $this;
                
                while ($category->parent && $category->parent->id) {
                    array_unshift($path, [
                        'id' => $category->parent->id,
                        'name' => $category->parent->name,
                    ]);
                    
                    // Предотвращаем зацикливание
                    if ($category->parent->id === $category->id) {
                        break;
                    }
                    
                    $category = $category->parent;
                }
                
                return $path;
            }),
        ];
    }
}
