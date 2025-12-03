<?php

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_request_id' => 'required|exists:purchase_requests,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date' => 'sometimes|date',
            'total_amount' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'delivery_date' => 'sometimes|date|after_or_equal:today',
            'notes' => 'sometimes|string|max:5000',
            'metadata' => 'sometimes|array',
        ];
    }
}

