<?php

namespace App\Http\Requests\Api\V1\Admin\RateCoefficient;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Enums\RateCoefficient\RateCoefficientTypeEnum;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Enums\RateCoefficient\RateCoefficientScopeEnum;
use App\DTOs\RateCoefficient\RateCoefficientDTO;
use App\Models\RateCoefficient; // Для получения текущих значений
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateRateCoefficientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|nullable|string|max:100', // уникальность будет проверяться в сервисе
            'value' => 'sometimes|required|numeric|min:0',
            'type' => ['sometimes', 'required', Rule::in(RateCoefficientTypeEnum::values())],
            'applies_to' => ['sometimes', 'required', Rule::in(RateCoefficientAppliesToEnum::values())],
            'scope' => ['sometimes', 'required', Rule::in(RateCoefficientScopeEnum::values())],
            'description' => 'sometimes|nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'valid_from' => 'sometimes|nullable|date_format:Y-m-d',
            'valid_to' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'conditions' => 'sometimes|nullable|array',
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
        /** @var RateCoefficient $currentCoefficient */
        $currentCoefficient = $this->route('rate_coefficient'); // rate_coefficient - имя параметра из роута

        return new RateCoefficientDTO(
            name: $validated['name'] ?? $currentCoefficient->name,
            value: isset($validated['value']) ? (float)$validated['value'] : (float)$currentCoefficient->value,
            type: isset($validated['type']) ? RateCoefficientTypeEnum::from($validated['type']) : $currentCoefficient->type,
            applies_to: isset($validated['applies_to']) ? RateCoefficientAppliesToEnum::from($validated['applies_to']) : $currentCoefficient->applies_to,
            scope: isset($validated['scope']) ? RateCoefficientScopeEnum::from($validated['scope']) : $currentCoefficient->scope,
            organization_id: $currentCoefficient->organization_id, // Не меняем
            code: $validated['code'] ?? $currentCoefficient->code,
            description: $validated['description'] ?? $currentCoefficient->description,
            is_active: $validated['is_active'] ?? $currentCoefficient->is_active,
            valid_from: isset($validated['valid_from']) ? Carbon::parse($validated['valid_from']) : $currentCoefficient->valid_from,
            valid_to: isset($validated['valid_to']) ? Carbon::parse($validated['valid_to']) : $currentCoefficient->valid_to,
            conditions: $validated['conditions'] ?? $currentCoefficient->conditions,
            id: $currentCoefficient->id
        );
    }
} 