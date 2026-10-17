<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveEvaluationScopeSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'selected_sections' => ['required', 'array', 'min:1', 'max:12'],
            'selected_sections.*' => ['required', 'string', 'distinct', 'max:120'],
            'profile_id' => ['required', 'in:construction,renovation,industrial'],
            'construction_type' => ['required', 'in:new_construction,current_repair,capital_repair,reconstruction'],
            'price_policy' => ['required', 'in:normative,catalog,mixed'],
            'selected_work_item_keys' => ['sometimes', 'array', 'min:1', 'max:128'],
            'selected_work_item_keys.*' => ['required', 'string', 'distinct', 'max:160'],
            'scope_reason' => ['required_with:selected_work_item_keys', 'string', 'min:10', 'max:1000'],
            'artifact_hash' => ['required_with:selected_work_item_keys', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'excluded_audit_finding_ids' => ['sometimes', 'array', 'max:128'],
            'excluded_audit_finding_ids.*' => ['required', 'string', 'distinct', 'max:191'],
            'accepted_material_finding_ids' => ['sometimes', 'array', 'max:128'],
            'accepted_material_finding_ids.*' => ['required', 'string', 'distinct', 'max:191']];
    }
}
