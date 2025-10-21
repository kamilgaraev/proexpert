<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateImportResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'estimate_id' => $this->estimateId,
            'status' => $this->status,
            'items' => [
                'total' => $this->itemsTotal,
                'imported' => $this->itemsImported,
                'skipped' => $this->itemsSkipped,
                'success_rate' => $this->getSuccessRate(),
            ],
            'sections_created' => $this->sectionsCreated,
            'new_work_types' => [
                'count' => count($this->newWorkTypesCreated),
                'items' => $this->newWorkTypesCreated,
            ],
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'processing_time_ms' => $this->processingTimeMs,
        ];
    }
}

