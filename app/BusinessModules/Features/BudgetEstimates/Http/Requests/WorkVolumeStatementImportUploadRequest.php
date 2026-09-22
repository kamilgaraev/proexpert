<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeStatementImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
            'operation_key' => ['required', 'string', 'max:128'],
            'first_data_row' => ['required', 'integer', 'min:1', 'max:10000'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'columns' => ['required', 'array:name,quantity,unit_code,place,line_key,measurement_formula,basis_revision'],
            'columns.*' => ['integer', 'min:0', 'max:127', 'distinct'],
            'source_file_path' => ['prohibited'], 'source_file_hash' => ['prohibited'],
        ];
    }
}
