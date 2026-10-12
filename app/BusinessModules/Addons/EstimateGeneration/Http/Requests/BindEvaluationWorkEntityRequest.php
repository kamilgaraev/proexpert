<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BindEvaluationWorkEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'work_item_key' => ['required', 'string', 'max:160'],
            'source_fact_id' => ['required', 'string', 'regex:/\Afact:(?:[a-f0-9]{64}|decision:[a-f0-9]{48})\z/'],
            'source_fact_version' => ['required', 'integer', 'min:1'],
            'source_value_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'reason' => ['required', 'string', 'min:12', 'max:300'], 'quantity' => ['prohibited'], 'unit' => ['prohibited']];
    }
}
