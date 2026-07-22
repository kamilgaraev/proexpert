<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GenerateEstimateGenerationRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.generate');
    }

    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'generation_mode' => ['nullable', 'string', Rule::in(EstimateGenerationMode::values())],
            'estimate_regional_price_version_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('estimate_regional_price_versions', 'id')
                    ->where('status', RegionalPriceStatus::ACTIVE->value),
            ],
        ];
    }
}
