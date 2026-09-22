<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeStatementImportSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_preview_version' => ['required', 'integer', 'min:1'],
            'rows' => ['present', 'array', 'max:10000'],
            'rows.*' => ['array'],
        ];
    }
}
