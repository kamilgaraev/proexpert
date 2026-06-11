<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmPipelineStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $labelRule = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'pipeline_id' => ['nullable', 'uuid'],
            'label' => [$labelRule, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'category' => ['nullable', 'string', Rule::in(['open', 'won', 'lost'])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'probability_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
