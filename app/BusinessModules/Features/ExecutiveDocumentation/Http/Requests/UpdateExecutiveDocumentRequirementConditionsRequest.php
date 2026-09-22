<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateExecutiveDocumentRequirementConditionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'conditions' => ['required', 'array:designer_supervision,separate_executor,required_relations'],
            'conditions.designer_supervision' => ['sometimes', 'boolean'],
            'conditions.separate_executor' => ['sometimes', 'boolean'],
            'conditions.required_relations' => ['sometimes', 'array', 'max:50'],
            'conditions.required_relations.*' => ['string', 'max:100', 'distinct'],
        ];
    }
}
