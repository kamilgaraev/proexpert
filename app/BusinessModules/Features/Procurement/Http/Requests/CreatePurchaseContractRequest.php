<?php

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'project_id' => 'sometimes|exists:projects,id',
            'number' => 'sometimes|string|max:255',
            'date' => 'required|date',
            'subject' => 'required|string|max:1000',
            'total_amount' => 'required|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'notes' => 'sometimes|string|max:5000',
        ];
    }
}

