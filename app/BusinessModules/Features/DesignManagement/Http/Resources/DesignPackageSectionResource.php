<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignPackageSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignPackageSection $section */
        $section = $this->resource;
        $status = $this->enumValue($section->status);

        return [
            'id' => $section->id,
            'organization_id' => $section->organization_id,
            'project_id' => $section->project_id,
            'package_id' => $section->package_id,
            'template_id' => $section->template_id,
            'code' => $section->code,
            'title' => $section->title,
            'project_stage' => $this->enumValue($section->project_stage),
            'object_type' => $this->enumValue($section->object_type),
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.sections.{$status}"),
            'required' => (bool) $section->required,
            'sort_order' => (int) $section->sort_order,
            'normative_reference' => $section->normative_reference,
            'metadata' => $section->metadata ?? [],
            'documents' => $section->metadata['documents'] ?? [],
            'artifacts' => DesignArtifactResource::collection($this->whenLoaded('artifacts')),
            'sheets' => DesignDocumentSheetResource::collection($this->whenLoaded('sheets')),
            'review_comments' => DesignReviewCommentResource::collection($this->whenLoaded('reviewComments')),
            'created_at' => $section->created_at?->toIso8601String(),
            'updated_at' => $section->updated_at?->toIso8601String(),
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
