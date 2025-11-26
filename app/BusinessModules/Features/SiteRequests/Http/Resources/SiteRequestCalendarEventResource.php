<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource для календарного события заявки
 */
class SiteRequestCalendarEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_request_id' => $this->site_request_id,
            'title' => $this->title,
            'description' => $this->description,
            'event_type' => $this->event_type->value,
            'event_type_label' => $this->event_type->label(),
            'color' => $this->color,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'all_day' => $this->all_day,
            'is_multi_day' => $this->is_multi_day,
            'duration_days' => $this->duration_days,

            // Связи
            'project' => $this->whenLoaded('siteRequest', fn() => $this->siteRequest->project ? [
                'id' => $this->siteRequest->project->id,
                'name' => $this->siteRequest->project->name,
            ] : null),
            'request' => $this->whenLoaded('siteRequest', fn() => [
                'id' => $this->siteRequest->id,
                'title' => $this->siteRequest->title,
                'status' => $this->siteRequest->status->value,
                'status_label' => $this->siteRequest->status->label(),
                'priority' => $this->siteRequest->priority->value,
                'priority_label' => $this->siteRequest->priority->label(),
                'request_type' => $this->siteRequest->request_type->value,
            ]),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

