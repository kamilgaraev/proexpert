<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Validation\Rule;

final class WarehouseStockExportRequest extends WarehouseBalancesRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $organizationId = (int) ($this->user()?->current_organization_id ?? 0);
        $warehouseId = (int) $this->route('id');

        $rules['asset_type'] = ['nullable', Rule::in([
            'material', 'equipment', 'tool', 'furniture', 'consumable', 'structure',
        ])];
        $rules['cell_id'] = [
            'nullable',
            'integer',
            Rule::exists('warehouse_storage_cells', 'id')->where(
                static fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId)
            ),
        ];

        return $rules;
    }
}
