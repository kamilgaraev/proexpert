<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class BankRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['amount_minor' => ['required', 'integer', 'min:1', 'max:99999999999999'],
            'kind' => ['required', 'in:payment,overpayment'], 'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'client_idempotency_key' => ['required', 'uuid']];
    }
}
