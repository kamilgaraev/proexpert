<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Requests\ProjectPulse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectPulseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) ($this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id);

        return [
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'period' => ['nullable', 'string', Rule::in(config('ai-assistant.project_pulse.periods', ['today', 'yesterday', 'week']))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', Rule::in(['good', 'warning', 'critical'])],
            'ai_status' => ['nullable', 'string', Rule::in(['active', 'unavailable', 'rules_only'])],
            'category' => ['nullable', 'string', Rule::in(array_keys(config('ai-assistant.project_pulse.categories', [])))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
