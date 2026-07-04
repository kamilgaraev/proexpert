<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SafetyInspectionTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $template = $this->resource;

        if (!$template instanceof SafetyInspectionTemplate) {
            return [];
        }

        return [
            'id' => $template->id,
            'organization_id' => $template->organization_id,
            'name' => $template->name,
            'inspection_type' => $template->inspection_type,
            'checklist_items' => $template->checklist_items ?? [],
            'is_active' => $template->is_active,
            'metadata' => $template->metadata,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
