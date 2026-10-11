<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveEvaluationRequirementDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'entity_id' => ['required', 'string', 'max:191', 'regex:/\A[a-z][a-z0-9._:-]+\z/'],
            'section' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]+\z/'],
            'requirement' => ['required', 'string', 'max:40', 'regex:/\A[a-z][a-z0-9_]+\z/'],
            'disposition' => ['required', Rule::in(['document_verified', 'not_applicable'])],
            'document_fact_id' => ['required_if:disposition,document_verified', 'prohibited_if:disposition,not_applicable', 'string', 'max:191', 'regex:/\A[a-z][a-z0-9._:-]+\z/'],
            'reason' => ['required', 'string', 'min:12', 'max:300']];
    }
}
