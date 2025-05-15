<?php

namespace App\Http\Resources\Api\V1\Mobile\SiteRequest;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectMiniResource; // Предполагаем использование админского mini ресурса
use App\Http\Resources\Api\V1\UserResource; // Общий UserResource
use App\Http\Resources\File\FileResource; // Исправленный путь

class SiteRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project' => new ProjectMiniResource($this->whenLoaded('project')),
            'user' => new UserResource($this->whenLoaded('user')), // Автор заявки
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value, // Отдаем значение Enum
            'status_label' => $this->status->name, // TODO: или метод для получения лейбла, если нужен перевод
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->name,
            'request_type' => $this->request_type->value,
            'request_type_label' => $this->request_type->name,
            'required_date' => $this->required_date ? $this->required_date->format('Y-m-d') : null,
            'notes' => $this->notes,
            'files' => FileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
} 