<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests\ProjectPulse;

use App\Rules\ProjectAccessibleRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateProjectPulseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => [
                'nullable',
                'integer',
                new ProjectAccessibleRule(),
            ],
            'period' => ['nullable', 'string', Rule::in(config('ai-assistant.project_pulse.periods', ['today', 'yesterday', 'week']))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'use_ai' => ['nullable', 'boolean'],
        ];
    }
}
