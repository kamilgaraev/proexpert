<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEntity;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShowEstimateGenerationProjectModelReviewRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.view');
    }

    public function rules(): array
    {
        return [
            'document_id' => ['nullable', 'integer', 'min:1'],
            'sheet_id' => ['nullable', 'integer', 'min:1'],
            'entity_kind' => ['nullable', 'string', Rule::in(ProjectModelEntity::KINDS)],
            'status' => ['nullable', 'string', Rule::in(['confirmed', 'needs_action', 'conflict', 'unconfirmed'])],
            'needs_action' => ['nullable', 'boolean'],
            'query' => ['nullable', 'string', 'max:128'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'state_version' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
