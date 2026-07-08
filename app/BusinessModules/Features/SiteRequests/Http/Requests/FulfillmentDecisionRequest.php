<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FulfillmentDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'in:warehouse,purchase,mixed'],
            'warehouse_id' => ['nullable', 'integer', 'min:1', 'required_if:source,warehouse,mixed'],
            'warehouse_quantity' => ['nullable', 'numeric', 'min:0.001', 'required_if:source,warehouse,mixed'],
            'purchase_quantity' => ['nullable', 'numeric', 'min:0.001', 'required_if:source,mixed'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
