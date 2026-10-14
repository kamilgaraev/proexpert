<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrVacancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'owner_id' => ['required', 'integer'], 'target_date' => ['nullable', 'date'],
            'stage_pipeline' => ['required', 'array', 'min:2', 'max:12'], 'stage_pipeline.*' => ['required', 'string', 'max:80', 'distinct'],
        ];
    }
}
