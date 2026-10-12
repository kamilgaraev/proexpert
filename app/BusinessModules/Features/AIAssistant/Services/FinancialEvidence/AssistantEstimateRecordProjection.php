<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;

final class AssistantEstimateRecordProjection
{
    public static function position(EstimateItem $item, bool $excluded, array $scales): array
    {
        $fields = [
            'id' => (int) $item->id, 'position_number' => (string) $item->position_number,
            'name' => (string) $item->name, 'normative_rate_code' => $item->normative_rate_code,
            'item_type' => (string) $item->getRawOriginal('item_type'),
            'parent_work_id' => $item->parent_work_id === null ? null : (int) $item->parent_work_id,
            'unit' => $item->measurementUnit?->short_name,
            'quantity' => FinanceDecimal::value((string) ($item->quantity_total ?? $item->quantity ?? '0'), 8),
            'excluded' => $excluded, 'included_in_total' => ! $excluded && $item->parent_work_id === null,
        ];
        foreach ($scales as $field => $scale) {
            $fields[$field] = $item->$field === null ? null : FinanceDecimal::value((string) $item->$field, $scale);
        }
        return $fields;
    }

    public static function child(EstimateItem $item): array
    {
        return [
            'id' => (int) $item->id, 'estimate_item_id' => (int) $item->id,
            'position_number' => (string) $item->position_number,
            'resource_type' => (string) $item->getRawOriginal('item_type'), 'name' => (string) $item->name,
            'quantity' => $item->getRawOriginal('quantity') === null ? null : FinanceDecimal::value((string) $item->getRawOriginal('quantity'), 8),
            'material_unit' => $item->measurementUnit?->short_name ?? $item->measurementUnit?->name,
            'unit_price' => $item->unit_price === null ? null : FinanceDecimal::value((string) $item->unit_price, 4),
            'currency' => null,
            'total_amount' => $item->total_amount === null ? null : FinanceDecimal::value((string) $item->total_amount, 2),
        ];
    }

    public static function resource(EstimateItemResource $resource): array
    {
        return [
            'id' => (int) $resource->id, 'estimate_item_id' => (int) $resource->estimate_item_id,
            'resource_type' => (string) $resource->resource_type, 'name' => (string) $resource->name,
            'quantity_per_unit' => FinanceDecimal::value((string) $resource->quantity_per_unit, 4),
            'total_quantity' => FinanceDecimal::value((string) $resource->total_quantity, 4),
            'material_unit' => $resource->measurementUnit?->short_name ?? $resource->measurementUnit?->name,
            'unit_price' => $resource->unit_price === null ? null : FinanceDecimal::value((string) $resource->unit_price, 2),
            'currency' => null,
            'total_amount' => $resource->total_amount === null ? null : FinanceDecimal::value((string) $resource->total_amount, 2),
        ];
    }
}
