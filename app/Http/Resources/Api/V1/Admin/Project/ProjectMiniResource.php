<?php

namespace App\Http\Resources\Api\V1\Admin\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMiniResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Предполагаем, что у модели Project есть поля id и name
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Можно добавить адрес или другие ключевые поля
        ];
    }
} 