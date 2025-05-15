<?php

namespace App\Http\Requests\Api\V1\Admin\RateCoefficient;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\DTOs\RateCoefficient\RateCoefficientDTO;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StoreRateCoefficientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check(); // Доступ к админке обычно проверяется middleware группы
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:100', // уникальность будет проверяться в сервисе
            'value' => 'required|numeric|min:0',
            'type' => ['required', Rule::in(RateCoefficientTypeEnum::values())],
            'applies_to' => ['required', Rule::in(RateCoefficientAppliesToEnum::values())],
            'scope' => ['required', Rule::in(RateCoefficientScopeEnum::values())],
            'description' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'valid_from' => 'nullable|date_format:Y-m-d',
            'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'conditions' => 'nullable|array',
            // Пример валидации для conditions, если нужны конкретные поля:
            // 'conditions.project_ids' => 'nullable|array',
            // 'conditions.project_ids.*' => 'integer|exists:projects,id',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = (new ValidationException($validator))->errors();
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Данные не прошли валидацию.',
                'errors' => $errors,
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    public function toDto(): RateCoefficientDTO
    {
        $validated = $this->validated();
        return new RateCoefficientDTO(
            name: $validated['name'],
            value: (float)$validated['value'],
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