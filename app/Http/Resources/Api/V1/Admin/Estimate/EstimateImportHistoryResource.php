<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateImportHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'status' => $this->status,
            'progress' => $this->progress,
            'estimate' => $this->whenLoaded('estimate', function() {
                return [
                    'id' => $this->estimate->id,
                    'name' => $this->estimate->name,
                ];
            }),
            'user' => $this->whenLoaded('user', function() {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
            'statistics' => [
                'items_total' => $this->items_total,
                'items_imported' => $this->items_imported,
                'items_skipped' => $this->items_skipped,
            ],
            'processing_time_ms' => $this->processing_time_ms,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

