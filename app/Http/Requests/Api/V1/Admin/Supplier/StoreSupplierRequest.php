<?php

namespace App\Http\Requests\Api\V1\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Используем тот же Gate, что и для других справочников
        return Gate::allows('manage-catalogs');
    }

    public function rules(): array
    {
        Log::info('[StoreSupplierRequest@rules] Starting rules method.', ['attributes' => $this->attributes->all()]);

        $organizationId = $this->attributes->get('organization_id');
        Log::info('[StoreSupplierRequest@rules] Organization ID from attributes:', ['organization_id' => $organizationId]);

        if (!$organizationId) {
            Log::warning('[StoreSupplierRequest@rules] Organization ID not found in attributes, returning empty rules array.');
            return [];
        }
        
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                 Rule::unique('suppliers', 'name')
                    ->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                                    ->whereNull('deleted_at');
                    }),
            ],
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 