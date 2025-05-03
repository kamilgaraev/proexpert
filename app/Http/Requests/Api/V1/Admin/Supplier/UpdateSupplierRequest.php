<?php

namespace App\Http\Requests\Api\V1\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Auth::user()->can('update', $this->route('supplier'))
        return true;
    }

    public function rules(): array
    {
        $supplierId = $this->route('supplier');
        return [
            'name' => 'sometimes|required|string|max:255', // TODO: Unique для организации, ignore $supplierId
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 