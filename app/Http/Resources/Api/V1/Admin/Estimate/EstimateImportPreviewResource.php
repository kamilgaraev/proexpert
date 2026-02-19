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
                // Поддержка как объектов DTO, так и массивов
                if (is_array($item)) {
                    return [
                        'row_number' => $item['row_number'],
                        'section_path' => $item['section_path'] ?? null,
                        'name' => $item['item_name'],
                        'unit' => $item['unit'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0),
                        'code' => $item['code'] ?? null,
                        'sub_items' => $item['sub_items'] ?? [],
                        // AI Classification fields
                        'item_type' => $item['item_type'] ?? 'work',
                        'confidence_score' => $item['confidence_score'] ?? null,
                        'classification_source' => $item['classification_source'] ?? null,
                        'has_math_mismatch' => $item['has_math_mismatch'] ?? false,
                        'warnings' => $item['warnings'] ?? [],
                    ];
                }
                
                // Объект EstimateImportRowDTO
                return [
                    'row_number' => $item->rowNumber,
                    'section_path' => $item->sectionPath ?? null,
                    'name' => $item->itemName,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice,
                    'total' => ($item->quantity ?? 0) * ($item->unitPrice ?? 0),
                    'code' => $item->code ?? null,
                    'sub_items' => $item->subItems ?? [],
                    // AI Classification fields
                    'item_type' => $item->itemType ?? 'work',
                    'confidence_score' => $item->confidenceScore ?? null,
                    'classification_source' => $item->classificationSource ?? null,
                    'has_math_mismatch' => $item->hasMathMismatch ?? false,
                    'warnings' => $item->warnings ?? [],
                ];
            }, array_slice($this->items, 0, 10)),
            'totals' => [
                'total_amount' => $this->totals['total_amount'] ?? 0,
                'items_count' => $this->totals['items_count'] ?? $this->totals['total_items'] ?? count($this->items),
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

