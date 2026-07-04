<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Project;

use App\DTOs\Project\ProjectDTO;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UpdateProjectRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_counterparty_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('counterparties', 'id')->where('organization_id', $this->currentOrganizationId()),
            ],
            'designer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'is_archived' => ['sometimes', 'boolean'],
            'additional_info' => ['sometimes', 'nullable', 'array'],
            'external_code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cost_category_id' => ['sometimes', 'nullable', 'exists:cost_categories,id'],
            'accounting_data' => ['sometimes', 'nullable', 'array'],
            'use_in_accounting_reports' => ['sometimes', 'nullable', 'boolean'],
            'budget_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'site_area_m2' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'contract_number' => ['sometimes', 'nullable', 'string', 'max:100'],
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
        $projectId = $this->route('project');
        $currentProject = $projectId instanceof Project ? $projectId : Project::find($projectId);

        if (!$currentProject) {
            throw new RuntimeException('Project not found for DTO conversion.');
        }

        return new ProjectDTO(
            name: $validated['name'] ?? $currentProject->name,
            address: $validated['address'] ?? $currentProject->address,
            latitude: array_key_exists('latitude', $validated)
                ? $this->nullableFloat($validated['latitude'])
                : $this->nullableFloat($currentProject->latitude),
            longitude: array_key_exists('longitude', $validated)
                ? $this->nullableFloat($validated['longitude'])
                : $this->nullableFloat($currentProject->longitude),
            description: $validated['description'] ?? $currentProject->description,
            customer: $validated['customer'] ?? $currentProject->customer,
            customer_counterparty_id: array_key_exists('customer_counterparty_id', $validated)
                ? ($validated['customer_counterparty_id'] !== null ? (int) $validated['customer_counterparty_id'] : null)
                : $currentProject->customer_counterparty_id,
            designer: $validated['designer'] ?? $currentProject->designer,
            budget_amount: array_key_exists('budget_amount', $validated)
                ? ($validated['budget_amount'] !== null ? (float) $validated['budget_amount'] : null)
                : ($currentProject->budget_amount !== null ? (float) $currentProject->budget_amount : null),
            site_area_m2: array_key_exists('site_area_m2', $validated)
                ? ($validated['site_area_m2'] !== null ? (float) $validated['site_area_m2'] : null)
                : ($currentProject->site_area_m2 !== null ? (float) $currentProject->site_area_m2 : null),
            contract_number: $validated['contract_number'] ?? $currentProject->contract_number,
            start_date: array_key_exists('start_date', $validated)
                ? $validated['start_date']
                : $currentProject->start_date?->toDateString(),
            end_date: array_key_exists('end_date', $validated)
                ? $validated['end_date']
                : $currentProject->end_date?->toDateString(),
            status: $validated['status'] ?? $currentProject->status,
            is_archived: $validated['is_archived'] ?? $currentProject->is_archived,
            additional_info: $validated['additional_info'] ?? $currentProject->additional_info,
            external_code: $validated['external_code'] ?? $currentProject->external_code,
            cost_category_id: array_key_exists('cost_category_id', $validated)
                ? ($validated['cost_category_id'] !== null ? (int) $validated['cost_category_id'] : null)
                : $currentProject->cost_category_id,
            accounting_data: $validated['accounting_data'] ?? $currentProject->accounting_data,
            use_in_accounting_reports: $validated['use_in_accounting_reports'] ?? $currentProject->use_in_accounting_reports
        );
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
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
