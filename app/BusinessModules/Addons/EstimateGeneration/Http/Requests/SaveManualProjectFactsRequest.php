<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class SaveManualProjectFactsRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'],
            'entities' => ['required', 'array', 'min:1', 'max:100'],
            'entities.*' => ['required', 'array:key,type,existing_entity_id,name,floor,zone,parent_key,parameters,coverage,coverage_fingerprints'],
            'entities.*.existing_entity_id' => ['sometimes', 'required', 'string', 'max:160'],
            'entities.*.key' => ['required', 'string', 'max:120'],
            'entities.*.name' => ['sometimes', 'required', 'string', 'max:160'],
            'entities.*.type' => ['required', \Illuminate\Validation\Rule::in(['room', 'wall', 'opening', 'site', 'roof', 'roof_facet', 'roof_opening',
                ...\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::engineeringTypes()])],
            'entities.*.floor' => ['nullable', 'string', 'max:120'], 'entities.*.zone' => ['nullable', 'string', 'max:120'],
            'entities.*.parent_key' => ['nullable', 'string', 'max:120'], 'entities.*.parameters' => ['sometimes', 'array', 'max:12'],
            'entities.*.parameters.*' => ['required', 'array:value,unit,basis'],
            'entities.*.parameters.*.value' => ['required', 'numeric', 'min:0', 'max:1000000000000', 'decimal:0,4'],
            'entities.*.parameters.*.unit' => ['required', 'string', 'max:16'],
            'entities.*.parameters.*.basis' => ['required', 'in:input,measurement,assumption'],
            'entities.*.coverage' => ['sometimes', 'array:room_walls,wall_openings,roof_facets,roof_openings'],
            'entities.*.coverage.*' => ['required', 'in:covered_empty,covered_with_entities,incomplete,unknown'],
            'entities.*.coverage_fingerprints' => ['sometimes', 'array:room_walls,wall_openings,roof_facets,roof_openings'],
            'entities.*.coverage_fingerprints.*' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
