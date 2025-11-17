<?php

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'due_date' => 'sometimes|date',
            'description' => 'sometimes|string|max:1000',
            'payment_terms' => 'sometimes|string|max:500',
            'notes' => 'sometimes|string|max:1000',
        ];
    }
}

