<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'row_number' => (int) $this->row_number,
            'raw_values' => $this->raw_values ?? [],
            'normalized_values' => $this->normalized_values ?? [],
            'decision' => $this->decision,
            'status' => $this->status,
            'validation_errors' => $this->validation_errors ?? [],
            'validation_warnings' => $this->validation_warnings ?? [],
            'duplicate_candidates' => $this->duplicate_candidates ?? [],
            'created_entity_id' => $this->created_entity_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
