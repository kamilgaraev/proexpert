<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveEvaluationTechnologySelectionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'planning_run_id' => ['required_without:current_fact_id', 'prohibited_with:current_fact_id', 'integer', 'min:1'],
            'decision_key' => ['required_without:current_fact_id', 'prohibited_with:current_fact_id', 'string', 'max:160'],
            'current_fact_id' => ['required_with:current_decision_id', 'string', 'max:191'],
            'current_decision_id' => ['required_with:current_fact_id', 'string', 'max:191'],
            'system_id' => ['required', 'string', 'max:160', 'not_in:other,leave_unresolved'],
            'reason' => ['required', 'string', 'max:1000']];
    }
}
