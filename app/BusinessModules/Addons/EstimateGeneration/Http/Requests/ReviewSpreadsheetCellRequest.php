<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewSpreadsheetCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'source_version' => ['required', 'string', 'regex:/\Asha256:[a-f0-9]{64}\z/D'],
            'unit_id' => ['required', 'integer', 'min:1'], 'proposal_run_id' => ['required', 'integer', 'min:1'],
            'proposal_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'decision' => ['required', 'in:accept,reject,revoke'], 'reason' => ['required', 'string', 'min:12', 'max:1000'],
            'identity_confirmation' => ['required_if:decision,accept', 'prohibited_unless:decision,accept', 'in:new_document_local_equipment']];
    }
}
