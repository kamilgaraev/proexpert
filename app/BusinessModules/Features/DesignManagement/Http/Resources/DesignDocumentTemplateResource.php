<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentTemplate;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignDocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignDocumentTemplate $template */
        $template = $this->resource;
        $artifactType = $this->enumValue($template->artifact_type);

        return [
            'id' => $template->id,
            'normative_source_id' => $template->normative_source_id,
            'profile_code' => $template->profile_code,
            'project_stage' => $this->enumValue($template->project_stage),
            'object_type' => $this->enumValue($template->object_type),
            'section_code' => $template->section_code,
            'section_title' => $template->section_title,
            'document_code' => $template->document_code,
            'document_title' => $template->document_title,
            'artifact_type' => $artifactType,
            'artifact_type_label' => trans_message("design_management.artifact_types.{$artifactType}"),
            'required' => (bool) $template->required,
            'sort_order' => (int) $template->sort_order,
            'allowed_formats' => $template->allowed_formats ?? [],
            'sheet_registry_required' => (bool) $template->sheet_registry_required,
            'normative_reference' => $template->normative_reference,
            'metadata' => $template->metadata ?? [],
            'normative_source' => new DesignNormativeSourceResource($this->whenLoaded('normativeSource')),
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
