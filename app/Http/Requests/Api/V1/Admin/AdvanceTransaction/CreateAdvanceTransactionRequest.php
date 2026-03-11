<?php

namespace App\Http\Requests\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAdvanceTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'nullable',
                'exists:users,id',
                Rule::requiredIf(fn (): bool => $this->input('type') !== 'issue' || !$this->filled('recipient_name')),
            ],
            'recipient_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('type') === 'issue' && !$this->filled('user_id')),
                Rule::prohibitedIf(fn (): bool => $this->input('type') !== 'issue'),
            ],
            'project_id' => 'nullable|exists:projects,id',
            'type' => 'required|in:issue,expense,return',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'cost_category_id' => 'nullable|exists:cost_categories,id',
        ];
    }
}
