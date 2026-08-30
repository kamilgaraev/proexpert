<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RemoveProjectAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ];
    }
}
