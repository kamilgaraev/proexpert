<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveEvaluationWorkCompositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'preview_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'work_keys' => ['required', 'array', 'min:1', 'max:32'],
            'work_keys.*' => ['required', 'string', 'distinct', 'max:160'],
            'reason' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
