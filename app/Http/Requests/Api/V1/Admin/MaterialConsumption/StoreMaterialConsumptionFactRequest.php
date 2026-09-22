<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\MaterialConsumption;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMaterialConsumptionFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('operation_key') && $this->header('Idempotency-Key')) {
            $this->merge(['operation_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'completed_work_id' => ['required', 'integer'],
            'material_id' => ['required', 'integer'],
            'kind' => ['required', 'in:consumption,return'],
            'quantity' => ['required', 'string', 'regex:/^[0-9]{1,18}(?:\.[0-9]{1,6})?$/'],
            'unit_id' => ['required', 'integer'],
            'occurred_on' => ['required', 'date'],
            'warehouse_movement_id' => ['nullable', 'integer'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'quality_document_id' => ['nullable', 'integer'],
            'deviation_reason' => ['nullable', 'string', 'max:4000'],
            'agreed_by_user_id' => ['nullable', 'integer'],
            'conversion' => ['nullable', 'array'],
            'conversion.coefficient' => ['required_with:conversion', 'string', 'regex:/^[0-9]{1,18}(?:\.[0-9]{1,6})?$/'],
            'conversion.reason' => ['required_with:conversion', 'string', 'min:1', 'max:2000'],
            'operation_key' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }
}
