<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractEstimateItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $item = $this->estimateItem;

        return [
            'id'                => $this->id,
            'contract_id'       => $this->contract_id,
            'estimate_id'       => $this->estimate_id,
            'estimate_item_id'  => $this->estimate_item_id,
            'quantity'          => (float) $this->quantity,
            'amount'            => (float) $this->amount,
            'notes'             => $this->notes,
            'item' => $item ? [
                'id'              => $item->id,
                'position_number' => $item->position_number,
                'name'            => $item->name,
                'item_type'       => $item->item_type instanceof \App\Enums\EstimatePositionItemType
                    ? $item->item_type->value
                    : $item->item_type,
                'quantity_total'  => (float) $item->quantity_total,
                'unit_price'      => (float) $item->unit_price,
                'total_amount'    => (float) $item->total_amount,
                'parent_work_id'  => $item->parent_work_id,
                'section_id'      => $item->estimate_section_id,
                'measurement_unit' => $item->relationLoaded('measurementUnit') && $item->measurementUnit
                    ? ['id' => $item->measurementUnit->id, 'short_name' => $item->measurementUnit->short_name]
                    : null,
                'contracts_count' => $item->contractLinks()->count(),
            ] : null,
        ];
    }
}
