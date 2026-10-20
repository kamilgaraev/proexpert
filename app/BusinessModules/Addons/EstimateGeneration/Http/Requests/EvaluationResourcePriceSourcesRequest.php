<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

final class EvaluationResourcePriceSourcesRequest extends EvaluationResourceTargetRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'norm_resource_id' => ['required', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']];
    }
}
