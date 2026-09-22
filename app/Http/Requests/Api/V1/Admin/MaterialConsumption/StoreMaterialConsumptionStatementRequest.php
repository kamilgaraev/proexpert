<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\MaterialConsumption;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMaterialConsumptionStatementRequest extends FormRequest
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
            'project_id' => ['required', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'document_date' => ['nullable', 'date'],
            'foreman_name' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }
}
