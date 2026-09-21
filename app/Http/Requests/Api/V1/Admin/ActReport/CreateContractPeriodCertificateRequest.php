<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

final class CreateContractPeriodCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->header('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'document_date' => ['nullable', 'date'],
            'act_ids' => ['nullable', 'array', 'min:1'],
            'act_ids.*' => ['integer', 'exists:contract_performance_acts,id'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }
}
