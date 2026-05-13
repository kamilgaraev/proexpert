<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('admin.catalogs.manage');
    }

    public function rules(): array
    {
        $organizationId = $this->attributes->get('current_organization_id');
        $supplierId = (int) $this->route('supplier');

        if (!$organizationId || $supplierId <= 0) {
            return [];
        }

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at'))
                    ->ignore($supplierId),
            ],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'organization_id' => ['sometimes', 'integer'],
        ];
    }
}
