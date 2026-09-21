<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAcceptanceWorkQuantitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'operation_key' => ['required', 'string', 'max:160'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.completed_work_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.presented_quantity' => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'lines.*.accepted_quantity' => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'lines.*.defect_quantity' => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'lines.*.defect_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
