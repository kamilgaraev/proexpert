<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportResultDTO;
use App\Http\Resources\ModelJsonResource;
use Illuminate\Http\Request;

class EstimateImportResultResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->typedResource(EstimateImportResultDTO::class);

        return [
            'estimate_id' => $this->estimateId,
            'status' => $this->status,
            'items' => [
                'total' => $this->itemsTotal,
                'imported' => $this->itemsImported,
                'skipped' => $this->itemsSkipped,
                'success_rate' => $result->getSuccessRate(),
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

