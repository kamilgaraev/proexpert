<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && (int) $this->attributes->get('current_organization_id') > 0;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'related_rfi_id' => ['nullable', 'integer'],
            'change_number' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'initiator_type' => ['required', Rule::in(['contractor', 'customer', 'designer', 'supervision', 'internal'])],
            'affected_schedule_task_ids' => ['nullable', 'array'],
            'affected_schedule_task_ids.*' => ['integer'],
            'affected_estimate_item_ids' => ['nullable', 'array'],
            'affected_estimate_item_ids.*' => ['integer'],
            'linked_entities' => ['nullable', 'array'],
            'monetary_context' => ['required', 'array'],
            'monetary_context.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'monetary_context.contract_project_allocation_id' => ['required', 'integer', 'min:1'],
            'monetary_context.contingency_opening_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'monetary_context.contingency_allocation_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'monetary_context.contingency_release_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ];
    }
}
