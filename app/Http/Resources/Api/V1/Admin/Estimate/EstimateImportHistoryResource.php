<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateImportHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stats = $this->stats ?? [];
        $result = $stats['result'] ?? [];
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'status' => $this->status,
            'progress' => (int) ($stats['progress'] ?? 0),
            'estimate_id' => $stats['estimate_id'] ?? null,
            'reason_code' => $stats['reason_code'] ?? null,
            'correlation_id' => $stats['correlation_id'] ?? null,
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
                'items_total' => (int) ($result['total_rows'] ?? $stats['total_rows'] ?? 0),
                'items_imported' => (int) ($result['items_created'] ?? $result['created_items'] ?? 0),
                'items_skipped' => (int) ($result['skipped_rows'] ?? 0),
            ],
            'processing_time_ms' => $stats['processing_time_ms'] ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

