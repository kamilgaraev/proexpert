<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class UpdateMdmQualityPolicyRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'required_fields' => ['required', 'array'],
            'required_fields.*' => ['string'],
            'field_weights' => ['required', 'array'],
            'validation_rules' => ['nullable', 'array'],
            'min_acceptable_score' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
