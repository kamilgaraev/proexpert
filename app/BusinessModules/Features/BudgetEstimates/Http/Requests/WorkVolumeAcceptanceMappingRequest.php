<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeAcceptanceMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'allocations' => ['present', 'array', 'max:1000'],
            'allocations.*' => ['required', 'array'],
            'allocations.*.statement_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'allocations.*.quantity' => ['required', 'string', 'regex:/^\d{1,18}(?:\.\d{1,6})?$/D'],
        ];
    }
}
