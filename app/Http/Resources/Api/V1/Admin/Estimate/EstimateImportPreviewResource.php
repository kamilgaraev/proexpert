<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateImportPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'file_format' => $this->fileFormat,
            'sections' => $this->sections,
            'items' => array_map(function($item) {
                return [
                    'row_number' => $item['row_number'],
                    'section_path' => $item['section_path'] ?? null,
                    'name' => $item['item_name'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0),
                    'code' => $item['code'] ?? null,
                ];
            }, array_slice($this->items, 0, 10)),
            'totals' => [
                'total_amount' => $this->totals['total_amount'],
                'items_count' => $this->totals['items_count'],
            ],
            'summary' => [
                'items_count' => $this->getItemsCount(),
                'sections_count' => $this->getSectionsCount(),
                'total_amount' => $this->getTotalAmount(),
            ],
            'metadata' => $this->metadata,
        ];
    }
}

