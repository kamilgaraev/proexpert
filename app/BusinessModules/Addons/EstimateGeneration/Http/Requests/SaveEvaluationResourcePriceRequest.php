<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

final class SaveEvaluationResourcePriceRequest extends EvaluationResourceTargetRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'norm_resource_id' => ['required', 'integer', 'min:1'],
            'target_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_reference' => ['required', 'string', 'max:100'], 'same_product_confirmed' => ['required', 'boolean'],
            'stale_accepted' => ['sometimes', 'boolean'], 'conditions_confirmation' => ['sometimes', 'array'],
            'reason' => ['required', 'string', 'max:1000']];
    }
}
