<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmEstimateGenerationGeometryRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'model_version' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'input_version' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'scale' => ['nullable', 'array:pixel_start,pixel_end,meters'],
            'scale.pixel_start' => ['required_with:scale', 'array', 'size:2'],
            'scale.pixel_end' => ['required_with:scale', 'array', 'size:2'],
            'scale.pixel_start.*' => ['numeric', 'between:-1000000,1000000'],
            'scale.pixel_end.*' => ['numeric', 'between:-1000000,1000000'],
            'scale.meters' => ['required_with:scale', 'numeric', 'gt:0', 'max:1000000'],
            'operations' => ['present', 'array', 'max:100'],
            'operations.*' => ['array:op,path,value'],
            'operations.*.op' => ['required', 'in:replace'],
            'operations.*.path' => ['required', 'string', 'max:256'],
            'operations.*.value' => ['present'],
        ];
    }
}
