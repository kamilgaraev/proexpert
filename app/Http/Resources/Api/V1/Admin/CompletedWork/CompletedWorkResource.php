<?php

namespace App\Http\Resources\Api\V1\Admin\CompletedWork;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectMiniResource; // Для краткой информации о проекте
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource; // Для краткой информации о контракте
use App\Http\Resources\Api\V1\UserResource; // Исправлено
use App\Http\Resources\Api\V1\Admin\WorkTypeResource; // Исправлено

class CompletedWorkResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project' => new ProjectMiniResource($this->whenLoaded('project')),
            'contract' => new ContractMiniResource($this->whenLoaded('contract')),
            'work_type' => new WorkTypeResource($this->whenLoaded('workType')), // Заменить на WorkTypeMiniResource если есть
            'user' => new UserResource($this->whenLoaded('user')), // Исправлено
            'quantity' => (float)$this->quantity,
            'price' => isset($this->price) ? (float)$this->price : null,
            'total_amount' => isset($this->total_amount) ? (float)$this->total_amount : null,
            'completion_date' => $this->completion_date->format('Y-m-d'),
            'notes' => $this->notes,
            'status' => $this->status,
            'additional_info' => $this->additional_info,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            // Можно добавить files, если они загружаются: 'files' => FileResource::collection($this->whenLoaded('files')),
        ];
    }
} 