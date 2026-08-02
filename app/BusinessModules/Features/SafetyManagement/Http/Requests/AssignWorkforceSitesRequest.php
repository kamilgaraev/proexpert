<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignWorkforceSitesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'employee_id' => ['required', 'integer', 'min:1'],
            'workforce_assignment_id' => ['required', 'integer', 'min:1'],
            'safety_site_ids' => ['required', 'array', 'min:1'],
            'safety_site_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }
}
