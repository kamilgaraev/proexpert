<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http;

use Illuminate\Foundation\Http\FormRequest;

final class EmployeeRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'], 'kind' => ['required', 'in:absence,correction'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'basis' => ['required', 'string', 'max:1000'],
            'requested_data' => ['required', 'array'],
            'requested_data.absence_type_code' => ['required_if:kind,absence', 'in:vacation,sick_leave,unpaid_leave,absence'],
            'requested_data.status' => ['required_if:kind,correction', 'in:at_work,absent,day_off'],
            'requested_data.hours' => ['required_if:requested_data.status,at_work', 'nullable', 'numeric', 'min:0', 'max:24'],
            'requested_data.project_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
