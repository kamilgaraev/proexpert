<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeStatementRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'statement_key' => ['nullable', 'uuid'],
            'operation_key' => ['nullable', 'string', 'max:128'],
            'name' => ['nullable', 'string', 'max:255'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'basis_revision' => ['nullable', 'string', 'max:255'],
            'source_file_path' => ['prohibited'],
            'source_file_hash' => ['prohibited'],
            'source_import_id' => ['prohibited'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_key' => ['required', 'uuid'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.unit_code' => ['required', 'string', 'max:32'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,18}(?:\.\d{1,6})?$/D'],
            'lines.*.place' => ['required', 'array', 'min:1'],
            'lines.*.measurement_formula' => ['nullable', 'string', 'max:2000'],
            'lines.*.basis_revision' => ['nullable', 'string', 'max:255'],
            'lines.*.estimate_item_id' => ['nullable', 'integer'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }
}
