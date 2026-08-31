<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryActRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()?->current_organization_id;

        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('organization_warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'inventory_date' => ['required', 'date'],
            'commission_members' => ['nullable', 'array'],
            'commission_members.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->whereIn('id', function ($query) use ($organizationId): void {
                        $query->select('user_id')
                            ->from('organization_user')
                            ->where('organization_id', $organizationId)
                            ->where('is_active', true);
                    }),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'commission_members.*.integer' => trans_message('basic_warehouse.inventory.commission_member_invalid'),
            'commission_members.*.exists' => trans_message('basic_warehouse.inventory.commission_member_invalid'),
            'commission_members.*.distinct' => trans_message('basic_warehouse.inventory.commission_member_duplicate'),
        ];
    }
}
