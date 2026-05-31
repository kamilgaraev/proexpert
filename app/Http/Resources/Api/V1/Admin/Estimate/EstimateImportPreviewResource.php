<?php

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\Http\Resources\ModelJsonResource;
use Illuminate\Http\Request;

class EstimateImportPreviewResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $preview = $this->typedResource(EstimateImportDTO::class);

        return [
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'file_format' => $this->fileFormat,
            'sections' => $this->sections,
            'items' => array_map(function($item) {
                // Поддержка как объектов DTO, так и массивов
                if (is_array($item)) {
                    $total = $item['current_total_amount']
                        ?? $item['total_amount']
                        ?? (($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0));

                    return [
                        'row_number' => $item['row_number'],
                        'section_path' => $item['section_path'] ?? null,
                        'name' => $item['item_name'],
                        'unit' => $item['unit'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => $total,
                        'code' => $item['code'] ?? null,
                        'sub_items' => $item['sub_items'] ?? [],
                        'is_sub_item' => $item['is_sub_item'] ?? false,
                        // AI Classification fields
                        'item_type' => $item['item_type'] ?? 'work',
                        'confidence_score' => $item['confidence_score'] ?? null,
                        'classification_source' => $item['classification_source'] ?? null,
                        'has_math_mismatch' => $item['has_math_mismatch'] ?? false,
                        'warnings' => $item['warnings'] ?? [],
                    ];
                }
                
                // Объект EstimateImportRowDTO
                $total = $item->currentTotalAmount ?? (($item->quantity ?? 0) * ($item->unitPrice ?? 0));

                return [
                    'row_number' => $item->rowNumber,
                    'section_path' => $item->sectionPath ?? null,
                    'name' => $item->itemName,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice,
                    'total' => $total,
                    'code' => $item->code ?? null,
                    'sub_items' => $item->subItems ?? [],
                    'is_sub_item' => $item->isSubItem ?? false,
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
                'items_count' => $preview->getItemsCount(),
                'sections_count' => $preview->getSectionsCount(),
                'total_amount' => $preview->getTotalAmount(),
            ],
            'metadata' => $this->metadata,
        ];
    }
}

