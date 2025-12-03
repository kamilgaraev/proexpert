<?php

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_request_id' => 'sometimes|exists:site_requests,id',
            'assigned_to' => 'sometimes|exists:users,id',
            'notes' => 'sometimes|string|max:5000',
            'metadata' => 'sometimes|array',
        ];
    }
}

