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
        $organizationId = $this->user()?->current_organization_id;

        return [
            'user_id' => [
                'nullable',
                Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId),
                Rule::requiredIf(fn (): bool => $this->input('type') !== 'issue' || !$this->filled('recipient_name')),
            ],
            'recipient_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('type') === 'issue' && !$this->filled('user_id')),
                Rule::prohibitedIf(fn (): bool => $this->input('type') !== 'issue'),
            ],
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'type' => 'required|in:issue,expense,return',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:100',
            'document_date' => 'nullable|date',
            'cost_category_id' => [
                'nullable',
                Rule::exists('cost_categories', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
