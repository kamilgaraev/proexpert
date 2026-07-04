<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Project;

use App\DTOs\Project\ProjectDTO;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreProjectRequest extends FormRequest
{
    private const ALLOWED_STATUSES = [
        'draft',
        'active',
        'completed',
        'paused',
        'cancelled',
    ];

    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer' => ['nullable', 'string', 'max:255'],
            'customer_counterparty_id' => [
                'nullable',
                'integer',
                Rule::exists('counterparties', 'id')->where('organization_id', $this->currentOrganizationId()),
            ],
            'designer' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'is_archived' => ['sometimes', 'boolean'],
            'additional_info' => ['nullable', 'array'],
            'external_code' => ['nullable', 'string', 'max:100'],
            'cost_category_id' => ['nullable', 'exists:cost_categories,id'],
            'accounting_data' => ['nullable', 'array'],
            'use_in_accounting_reports' => ['nullable', 'boolean'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'site_area_m2' => ['nullable', 'numeric', 'min:0'],
            'contract_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => trans_message('project.validation.name_required'),
            'end_date.after_or_equal' => trans_message('project.validation.end_date_after_or_equal'),
            'status.in' => trans_message('project.validation.status_invalid'),
            'cost_category_id.exists' => trans_message('project.validation.cost_category_not_found'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            AdminResponse::error(
                trans_message('errors.validation_failed'),
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                $errors
            )
        );
    }

    public function toDto(): ProjectDTO
    {
        $validated = $this->validated();

        return new ProjectDTO(
            name: $validated['name'],
            address: $validated['address'] ?? null,
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            description: $validated['description'] ?? null,
            customer: $validated['customer'] ?? null,
            customer_counterparty_id: isset($validated['customer_counterparty_id'])
                ? (int) $validated['customer_counterparty_id']
                : null,
            designer: $validated['designer'] ?? null,
            budget_amount: isset($validated['budget_amount']) ? (float) $validated['budget_amount'] : null,
            site_area_m2: isset($validated['site_area_m2']) ? (float) $validated['site_area_m2'] : null,
            contract_number: $validated['contract_number'] ?? null,
            start_date: $validated['start_date'] ?? null,
            end_date: $validated['end_date'] ?? null,
            status: $validated['status'],
            is_archived: $validated['is_archived'] ?? false,
            additional_info: $validated['additional_info'] ?? null,
            external_code: $validated['external_code'] ?? null,
            cost_category_id: isset($validated['cost_category_id']) ? (int) $validated['cost_category_id'] : null,
            accounting_data: $validated['accounting_data'] ?? null,
            use_in_accounting_reports: $validated['use_in_accounting_reports'] ?? false
        );
    }

    private function currentOrganizationId(): int
    {
        return (int) (
            $this->attributes->get('current_organization_id')
            ?? $this->user()?->current_organization_id
            ?? 0
        );
    }
}
