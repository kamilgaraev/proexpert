<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile\System;

use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MobileSystemRateCoefficientIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'applies_to' => ['required', 'string', Rule::enum(RateCoefficientAppliesToEnum::class)],
            'scope' => ['sometimes', 'nullable', 'string', Rule::enum(RateCoefficientScopeEnum::class)],
        ];
    }
}
