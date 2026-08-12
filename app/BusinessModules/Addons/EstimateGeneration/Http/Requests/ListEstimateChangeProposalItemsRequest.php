<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class ListEstimateChangeProposalItemsRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.view');
    }

    public function rules(): array
    {
        return ['limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'integer', 'min:1']];
    }
}
