<?php

namespace App\Http\Requests\Api\V1\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use App\Models\Supplier; // Импортируем модель

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Supplier|null $supplier */
        $supplier = $this->route('supplier');
        
        // Проверяем права и принадлежность
        return $supplier && Gate::allows('admin.catalogs.manage') && $supplier->organization_id === (int)$this->get('current_organization_id');
    }

    public function rules(): array
    {
        /** @var Supplier|null $supplier */
        $supplier = $this->route('supplier');
        $supplierId = $supplier?->id;
        $organizationId = $this->get('current_organization_id');
        
        if (!$organizationId || !$supplierId) {
            return [];
        }

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                 Rule::unique('suppliers', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    })
                    ->ignore($supplierId), // Игнорируем текущий ID
            ],
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ];
    }
}