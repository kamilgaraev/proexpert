<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'source_format' => $this->source_format,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'total_rows' => (int) $this->total_rows,
            'accepted_rows' => (int) $this->accepted_rows,
            'warning_rows' => (int) $this->warning_rows,
            'blocked_rows' => (int) $this->blocked_rows,
            'progress_percent' => (int) $this->progress_percent,
            'mapping' => $this->mapping ?? [],
            'summary' => $this->summary ?? [],
            'confirmed_at' => $this->confirmed_at,
            'cancelled_at' => $this->cancelled_at,
            'rows' => CrmImportRowResource::collection($this->whenLoaded('rows')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
