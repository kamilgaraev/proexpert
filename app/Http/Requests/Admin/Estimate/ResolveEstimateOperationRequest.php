<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Estimate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveEstimateOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'refresh' => ['sometimes', 'boolean'], 'resolutions' => ['present', 'array', 'max:1000'],
            'resolutions.*' => ['array:action,target_key,unit_factor,unit_price,source,price_candidate'],
            'resolutions.*.action' => ['sometimes', Rule::in(['separate', 'match'])],
            'resolutions.*.target_key' => ['sometimes', 'string', 'regex:/^\d+:\d+$/'],
            'resolutions.*.unit_factor' => ['sometimes', 'numeric', 'gt:0', 'max:100000000', 'regex:/^\d+(\.\d{1,8})?$/'],
            'resolutions.*.unit_price' => ['sometimes', 'numeric', 'min:0', 'max:1000000000000', 'regex:/^\d+(\.\d{1,4})?$/'],
            'resolutions.*.source' => ['sometimes', 'string', 'min:3', 'max:500'],
            'resolutions.*.price_candidate' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
