<?php

namespace App\BusinessModules\Addons\AIEstimates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeneratedEstimateDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'generation_id' => $this->generation_id ?? null,
            'sections' => $this->sections ?? [],
            'items' => $this->items ?? [],
            'totals' => [
                'total_cost' => $this->total_cost ?? 0,
                'items_count' => count($this->items ?? []),
                'sections_count' => count($this->sections ?? []),
            ],
            'confidence' => [
                'average' => $this->average_confidence ?? 0,
                'high_confidence_items' => $this->countHighConfidenceItems(),
                'low_confidence_items' => $this->countLowConfidenceItems(),
            ],
            'metadata' => [
                'tokens_used' => $this->tokens_used ?? 0,
                'processing_time' => $this->processing_time ?? null,
            ],
        ];
    }

    protected function countHighConfidenceItems(): int
    {
        $items = $this->items ?? [];
        return count(array_filter($items, fn($item) => ($item['confidence'] ?? 0) >= 0.75));
    }

    protected function countLowConfidenceItems(): int
    {
        $items = $this->items ?? [];
        return count(array_filter($items, fn($item) => ($item['confidence'] ?? 0) < 0.60));
    }
}
