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
            'entities.*' => ['required', 'array:key,type,existing_entity_id,name,floor,zone,parent_key,parameters,coverage,coverage_fingerprints,parent_choice,native_question,wall_assembly'],
            'entities.*.wall_assembly' => ['sometimes', 'required', 'array:role,finished_surfaces,entity_fingerprint,reason'],
            'entities.*.wall_assembly.role' => ['required_with:entities.*.wall_assembly', 'in:external,partition'],
            'entities.*.wall_assembly.finished_surfaces' => ['present_with:entities.*.wall_assembly', 'array', 'max:2'],
            'entities.*.wall_assembly.finished_surfaces.*' => ['required', 'in:inside,outside,side_a,side_b'],
            'entities.*.wall_assembly.entity_fingerprint' => ['required_with:entities.*.wall_assembly', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.wall_assembly.reason' => ['required_with:entities.*.wall_assembly', 'string', 'max:1000'],
            'entities.*.native_question' => ['sometimes', 'required', 'array:key,version,input_fingerprint,entity_source_version'],
            'entities.*.native_question.key' => ['required_with:entities.*.native_question', 'string', 'regex:/\Anative-parameter:[a-f0-9]{64}\z/'],
            'entities.*.native_question.version' => ['required_with:entities.*.native_question', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.native_question.input_fingerprint' => ['required_with:entities.*.native_question', 'string', 'max:160'],
            'entities.*.native_question.entity_source_version' => ['required_with:entities.*.native_question', 'string', 'max:160'],
            'entities.*.existing_entity_id' => ['sometimes', 'required', 'string', 'max:160'],
            'entities.*.key' => ['required', 'string', 'max:120'],
            'entities.*.name' => ['sometimes', 'required', 'string', 'max:160'],
            'entities.*.type' => ['required', \Illuminate\Validation\Rule::in(['room', 'wall', 'opening', 'site', 'roof', 'roof_facet', 'roof_opening',
                'foundation_beam', 'foundation_slab',
                ...\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::engineeringTypes()])],
            'entities.*.floor' => ['nullable', 'string', 'max:120'], 'entities.*.zone' => ['nullable', 'string', 'max:120'],
            'entities.*.parent_key' => ['nullable', 'string', 'max:120'], 'entities.*.parameters' => ['sometimes', 'array', 'max:12'],
            'entities.*.parameters.*' => ['required', 'array:value,unit,basis,description_proposal'],
            'entities.*.parameters.*.value' => ['required', 'numeric', 'min:0', 'max:1000000000000', 'decimal:0,4'],
            'entities.*.parameters.*.unit' => ['required', 'string', 'max:16'],
            'entities.*.parameters.*.basis' => ['required', 'in:input,measurement,assumption'],
            'entities.*.parameters.*.description_proposal' => ['sometimes', 'required', 'array:proposal_id,source_fact_id,source_fact_version,source_value_hash'],
            'entities.*.parameters.*.description_proposal.proposal_id' => ['required_with:entities.*.parameters.*.description_proposal', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.parameters.*.description_proposal.source_fact_id' => ['required_with:entities.*.parameters.*.description_proposal', 'string', 'max:160'],
            'entities.*.parameters.*.description_proposal.source_fact_version' => ['required_with:entities.*.parameters.*.description_proposal', 'integer', 'min:1'],
            'entities.*.parameters.*.description_proposal.source_value_hash' => ['required_with:entities.*.parameters.*.description_proposal', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.coverage' => ['sometimes', 'array:room_walls,wall_openings,roof_facets,roof_openings'],
            'entities.*.coverage.*' => ['required', 'in:covered_empty,covered_with_entities,incomplete,unknown'],
            'entities.*.coverage_fingerprints' => ['sometimes', 'array:room_walls,wall_openings,roof_facets,roof_openings'],
            'entities.*.coverage_fingerprints.*' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.parent_choice' => ['sometimes', 'required', 'array:relation,parent_entity_id,inventory_fingerprint,child_fingerprint,reason'],
            'entities.*.parent_choice.relation' => ['required_with:entities.*.parent_choice', 'in:room_walls,wall_openings,roof_facets,roof_openings'],
            'entities.*.parent_choice.parent_entity_id' => ['required_with:entities.*.parent_choice', 'string', 'max:160'],
            'entities.*.parent_choice.inventory_fingerprint' => ['required_with:entities.*.parent_choice', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.parent_choice.child_fingerprint' => ['required_with:entities.*.parent_choice', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'entities.*.parent_choice.reason' => ['required_with:entities.*.parent_choice', 'string', 'max:1000'],
        ];
    }
}
