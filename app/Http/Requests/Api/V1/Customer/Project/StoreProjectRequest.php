<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Customer\Project;

use App\DTOs\Project\ProjectDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

use function trans_message;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'customer' => ['nullable', 'string', 'max:255'],
            'designer' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string', 'in:active,completed,paused,cancelled'],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'site_area_m2' => ['nullable', 'numeric', 'min:0'],
            'contract_number' => ['nullable', 'string', 'max:100'],
            'additional_info' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => trans_message('customer.projects.validation.name_required'),
            'end_date.after_or_equal' => trans_message('customer.projects.validation.end_date_after_or_equal'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            \App\Http\Responses\CustomerResponse::error(
                trans_message('customer.validation_failed'),
                422,
                $errors
            )
        );
    }

    public function toDto(): ProjectDTO
    {
        $validated = $this->validated();

        return new ProjectDTO(
            name: (string) $validated['name'],
            address: isset($validated['address']) ? (string) $validated['address'] : null,
            latitude: null,
            longitude: null,
            description: isset($validated['description']) ? (string) $validated['description'] : null,
            customer: isset($validated['customer']) ? (string) $validated['customer'] : null,
            designer: isset($validated['designer']) ? (string) $validated['designer'] : null,
            budget_amount: isset($validated['budget_amount']) ? (float) $validated['budget_amount'] : null,
            site_area_m2: isset($validated['site_area_m2']) ? (float) $validated['site_area_m2'] : null,
            contract_number: isset($validated['contract_number']) ? (string) $validated['contract_number'] : null,
            start_date: isset($validated['start_date']) ? (string) $validated['start_date'] : null,
            end_date: isset($validated['end_date']) ? (string) $validated['end_date'] : null,
            status: isset($validated['status']) ? (string) $validated['status'] : 'active',
            is_archived: false,
            additional_info: isset($validated['additional_info']) && is_array($validated['additional_info'])
                ? $validated['additional_info']
                : null,
            external_code: null,
            cost_category_id: null,
            accounting_data: null,
            use_in_accounting_reports: false,
        );
    }
}
