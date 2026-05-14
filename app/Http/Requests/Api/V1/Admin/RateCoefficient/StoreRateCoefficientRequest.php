<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\RateCoefficient;

use App\DTOs\RateCoefficient\RateCoefficientDTO;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Http\Responses\AdminResponse;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreRateCoefficientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:100',
            'value' => 'required|numeric|min:0',
            'type' => ['required', Rule::in(RateCoefficientTypeEnum::values())],
            'applies_to' => ['required', Rule::in(RateCoefficientAppliesToEnum::values())],
            'scope' => ['required', Rule::in(RateCoefficientScopeEnum::values())],
            'description' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'valid_from' => 'nullable|date_format:Y-m-d',
            'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'conditions' => 'nullable|array',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = (new ValidationException($validator))->errors();

        throw new HttpResponseException(
            AdminResponse::error(trans_message('rate_coefficients.validation_error'), 422, $errors)
        );
    }

    public function toDto(): RateCoefficientDTO
    {
        $validated = $this->validated();

        return new RateCoefficientDTO(
            name: $validated['name'],
            value: (float) $validated['value'],
            type: RateCoefficientTypeEnum::from($validated['type']),
            applies_to: RateCoefficientAppliesToEnum::from($validated['applies_to']),
            scope: RateCoefficientScopeEnum::from($validated['scope']),
            code: $validated['code'] ?? null,
            description: $validated['description'] ?? null,
            is_active: $validated['is_active'] ?? true,
            valid_from: isset($validated['valid_from']) ? Carbon::parse($validated['valid_from']) : null,
            valid_to: isset($validated['valid_to']) ? Carbon::parse($validated['valid_to']) : null,
            conditions: $validated['conditions'] ?? null
        );
    }
}
