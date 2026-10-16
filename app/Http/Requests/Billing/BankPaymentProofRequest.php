<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class BankPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'b2b_payment' => ['required', 'accepted']];
    }

    public function messages(): array
    {
        return ['file.*' => trans_message('bank_billing.proof_file_invalid'),
            'b2b_payment.*' => trans_message('bank_billing.proof_b2b_required')];
    }
}
