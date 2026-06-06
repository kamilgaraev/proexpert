<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignDocumentSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignDocumentSheet $sheet */
        $sheet = $this->resource;

        return [
            'id' => $sheet->id,
            'organization_id' => $sheet->organization_id,
            'project_id' => $sheet->project_id,
            'package_id' => $sheet->package_id,
            'section_id' => $sheet->section_id,
            'artifact_id' => $sheet->artifact_id,
            'version_id' => $sheet->version_id,
            'sheet_number' => $sheet->sheet_number,
            'sheet_code' => $sheet->sheet_code,
            'sheet_title' => $sheet->sheet_title,
            'revision' => $sheet->revision,
            'file_page_number' => $sheet->file_page_number,
            'total_sheets' => $sheet->total_sheets,
            'status' => $sheet->status,
            'metadata' => $sheet->metadata ?? [],
            'created_at' => $sheet->created_at?->toIso8601String(),
            'updated_at' => $sheet->updated_at?->toIso8601String(),
        ];
    }
}
