<?php

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:payment_documents,id',
            'action' => 'required|string|in:submit,approve,pay,cancel,schedule',
            'reason' => 'required_if:action,cancel,reject|string|min:3',
            'scheduled_at' => 'required_if:action,schedule|date',
        ];
    }
}

