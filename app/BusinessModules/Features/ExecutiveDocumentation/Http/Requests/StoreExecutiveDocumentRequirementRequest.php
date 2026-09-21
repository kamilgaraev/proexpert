<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreExecutiveDocumentRequirementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expected_composition_revision' => ['required', 'integer', 'min:0'],
            'operation_key' => ['required', 'string', 'max:128'],
            'requirements' => ['required', 'array', 'min:1', 'max:500'],
            'requirements.*.profile_type' => ['required', 'string', 'max:100'],
            'requirements.*.requirement_key' => ['required', 'string', 'max:128'],
            'requirements.*.title' => ['required', 'string', 'max:255'],
            'requirements.*.stage' => ['required', 'string', 'max:64'],
            'requirements.*.source' => ['required', 'string', 'max:255'],
            'requirements.*.source_revision' => ['required', 'string', 'max:128'],
            'requirements.*.applicability' => ['sometimes', 'in:required,conditional,not_applicable'],
            'requirements.*.not_applicable_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'requirements.*.work_type_id' => ['sometimes', 'nullable', 'integer'],
            'requirements.*.project_location_id' => ['sometimes', 'nullable', 'integer'],
            'requirements.*.completed_work_id' => ['sometimes', 'nullable', 'integer'],
            'requirements.*.rule_snapshot' => ['prohibited'],
            'requirements.*.coverage_scope' => ['sometimes', 'array'],
            'requirements.*.conditions' => ['sometimes', 'array:designer_supervision,separate_executor,required_relations'],
            'requirements.*.conditions.designer_supervision' => ['sometimes', 'boolean'],
            'requirements.*.conditions.separate_executor' => ['sometimes', 'boolean'],
            'requirements.*.conditions.required_relations' => ['sometimes', 'array', 'max:50'],
            'requirements.*.conditions.required_relations.*' => ['string', 'max:100', 'distinct'],
        ];
    }
}
