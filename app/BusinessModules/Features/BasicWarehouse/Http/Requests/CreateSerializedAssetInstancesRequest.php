<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateSerializedAssetInstancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) $this->user()->current_organization_id;

        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists(OrganizationWarehouse::class, 'id')->where(
                    static fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->where('is_active', true),
                ),
            ],
            'instances' => ['required', 'array', 'min:1', 'max:500'],
            'instances.*.inventory_number' => ['required', 'string', 'max:120', 'distinct:strict'],
            'instances.*.serial_number' => ['nullable', 'string', 'max:160', 'distinct:strict'],
            'instances.*.qr_code' => ['nullable', 'string', 'max:160', 'distinct:strict'],
        ];
    }
}
