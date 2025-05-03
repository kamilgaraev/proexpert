<?php

namespace App\Http\Requests\Api\V1\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Auth::user()->can('create', Supplier::class)
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255', // TODO: Unique для организации
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 