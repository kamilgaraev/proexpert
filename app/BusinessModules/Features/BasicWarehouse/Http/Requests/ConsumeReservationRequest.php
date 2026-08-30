<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConsumeReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
