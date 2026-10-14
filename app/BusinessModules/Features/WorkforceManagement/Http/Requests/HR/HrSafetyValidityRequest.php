<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrSafetyValidityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer'], 'work_category' => ['nullable', 'string', 'max:80'], 'work_date' => ['nullable', 'date'],
        ];
    }
}
