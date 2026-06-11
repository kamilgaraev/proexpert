<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmDealStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pipeline_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'pipeline_code' => ['nullable', 'string', 'max:64'],
            'stage_code' => ['required_without:stage_id', 'string', 'max:64'],
            'status' => ['nullable', 'string', Rule::in(['open', 'won', 'lost'])],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'lost_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
