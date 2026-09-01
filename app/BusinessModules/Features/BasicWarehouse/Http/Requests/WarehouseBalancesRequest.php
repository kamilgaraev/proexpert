<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WarehouseBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $booleanFilters = [];

        foreach (['missing_location', 'low_stock'] as $filter) {
            $value = $this->input($filter);

            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $booleanFilters[$filter] = strtolower($value) === 'true';
            }
        }

        $this->merge($booleanFilters);
    }

    public function rules(): array
    {
        $organizationId = (int) ($this->user()?->current_organization_id ?? 0);
        $routeWarehouseId = $this->route('id');
        $warehouseId = is_int($routeWarehouseId) || (is_string($routeWarehouseId) && ctype_digit($routeWarehouseId))
            ? (int) $routeWarehouseId
            : 0;

        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'missing_location' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'zone_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_zones', 'id')->where(
                    static fn ($query) => $query
                        ->where('warehouse_id', $warehouseId)
                        ->whereExists(static fn ($warehouseQuery) => $warehouseQuery
                            ->selectRaw('1')
                            ->from('organization_warehouses')
                            ->whereColumn('organization_warehouses.id', 'warehouse_zones.warehouse_id')
                            ->where('organization_warehouses.organization_id', $organizationId))
                ),
            ],
        ];
    }
}
