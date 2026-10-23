<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

final class AnswerEstimateClarificationRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.review');
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'max:160'],
            'other' => ['nullable', 'string', 'max:500', 'required_if:response,other', 'prohibited_unless:response,other'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/D'],
            'expected_source_version' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/D'],
            'answer_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'target_entity_id' => ['sometimes', 'required', 'string', 'regex:/^[a-z][a-z0-9._:-]{1,191}$/D', 'prohibited_unless:response,other'],
            'document_ids' => ['sometimes', 'array', 'list', 'max:8', 'prohibited_unless:response,other'],
            'document_ids.*' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'parameter' => ['sometimes', 'array:name,value,unit,basis,source_operand_ref,condition', 'prohibited_unless:response,other'],
            'parameter.name' => ['required_with:parameter', 'string', 'max:160'],
            'parameter.value' => ['required_with:parameter', 'string', 'max:64'],
            'parameter.unit' => ['required_with:parameter', 'string', 'max:32'],
            'parameter.basis' => ['required_with:parameter', 'in:input,measurement,assumption'],
            'parameter.source_operand_ref' => ['required_with:parameter', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'parameter.condition' => ['required_with:parameter', 'in:minimum_length'],
        ];
    }
}
