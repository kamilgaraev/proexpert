<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project' => new ProjectMiniResource($this->whenLoaded('project')),
            'author' => new UserResource($this->whenLoaded('user')),
            'title' => $this->title,
            'description' => $this->description,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->name,
            ],
            'priority' => [
                'value' => $this->priority->value,
                'label' => $this->priority->name,
            ],
            'request_type' => [
                'value' => $this->request_type->value,
                'label' => $this->request_type->name,
            ],
            'required_date' => $this->required_date?->format('Y-m-d'),
            'notes' => $this->notes,
            
            // Поля для заявок на персонал
            'personnel_type' => $this->personnel_type ? [
                'value' => $this->personnel_type->value,
                'label' => $this->personnel_type->name,
            ] : null,
            'personnel_count' => $this->personnel_count,
            'personnel_requirements' => $this->personnel_requirements,
            'hourly_rate' => $this->hourly_rate,
            'work_hours_per_day' => $this->work_hours_per_day,
            'work_start_date' => $this->work_start_date?->format('Y-m-d'),
            'work_end_date' => $this->work_end_date?->format('Y-m-d'),
            'work_location' => $this->work_location,
            'additional_conditions' => $this->additional_conditions,
            
            'files' => FileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}