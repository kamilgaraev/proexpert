<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\MaterialConsumption;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMaterialConsumptionRateRequest extends FormRequest
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
            'project_id' => ['nullable', 'integer'],
            'material_id' => ['required', 'integer'],
            'work_type_id' => ['required', 'integer'],
            'estimate_item_id' => ['nullable', 'integer'],
            'work_unit_id' => ['required', 'integer'],
            'material_unit_id' => ['required', 'integer'],
            'quantity_per_work_unit' => ['required', 'string', 'regex:/^[0-9]{1,18}(?:\.[0-9]{1,6})?$/'],
            'basis_kind' => ['required', 'in:gesn,project,tech_card'],
            'basis_text' => ['required', 'string', 'min:2', 'max:4000'],
            'basis_document_id' => ['nullable', 'integer'],
            'work_type_material_id' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }
}
