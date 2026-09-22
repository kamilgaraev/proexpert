<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeStatementImportRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_preview_version' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'basis_revision' => ['nullable', 'string', 'max:255'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'based_on_statement_id' => ['nullable', 'integer', 'min:1'],
            'source_file_path' => ['prohibited'], 'source_file_hash' => ['prohibited'],
        ];
    }
}
