<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteRequestGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'project' => [
                'id' => $this->project_id,
                'name' => $this->project->name ?? null,
            ],
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user->name ?? null,
            ],
            'requests' => SiteRequestResource::collection($this->whenLoaded('requests')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
