<?php

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'proposal_date' => 'sometimes|date',
            'total_amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'valid_until' => 'sometimes|date|after:today',
            'items' => 'sometimes|array',
            'items.*.name' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.unit' => 'required_with:items|string|max:50',
            'items.*.price' => 'required_with:items|numeric|min:0',
            'notes' => 'sometimes|string|max:5000',
            'metadata' => 'sometimes|array',
        ];
    }
}

