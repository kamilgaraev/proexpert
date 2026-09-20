<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkVolumeStatementImportPreviewRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'max:10000'],
            'rows.*' => ['array'],
            'rows.*.line_key' => ['nullable', 'string', 'max:64'],
            'rows.*.name' => ['nullable', 'string', 'max:2000'],
            'rows.*.unit_code' => ['nullable', 'string', 'max:128'],
            'rows.*.quantity' => ['nullable', 'string', 'max:128'],
            'rows.*.place' => ['nullable', 'array'],
        ];
    }
}
