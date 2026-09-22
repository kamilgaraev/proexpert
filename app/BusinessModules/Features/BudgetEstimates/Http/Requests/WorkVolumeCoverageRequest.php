<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeCoverageRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_coverage_revision' => ['required', 'integer', 'min:0'],
            'allocations' => ['present', 'array', 'max:10000'],
            'allocations.*' => ['array'],
            'allocations.*.statement_line_id' => ['required', 'integer'],
            'allocations.*.contract_id' => ['required', 'integer'],
            'allocations.*.estimate_id' => ['required', 'integer'],
            'allocations.*.estimate_item_id' => ['required', 'integer'],
            'allocations.*.quantity' => ['required', 'string', 'regex:/^\d{1,18}(?:\.\d{1,6})?$/D'],
            'allocations.*.unit_code' => ['required', 'string', 'max:32'],
            'allocations.*.source_link_id' => ['nullable', 'integer'],
            'allocations.*.conversion_basis' => ['nullable', 'array:coefficient,reason,source_link_id'],
            'allocations.*.conversion_basis.coefficient' => ['required_with:allocations.*.conversion_basis', 'string', 'regex:/^\d{1,12}(?:\.\d{1,12})?$/D'],
            'allocations.*.conversion_basis.reason' => ['required_with:allocations.*.conversion_basis', 'string', 'max:2000'],
            'allocations.*.conversion_basis.source_link_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
