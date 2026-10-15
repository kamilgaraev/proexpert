<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Core\Payments\Http\Requests\Concerns\AuthorizesPaymentDocuments;

final class PreviewPaymentDocumentBudgetRequest extends FormRequest
{
    use AuthorizesPaymentDocuments;

    public function authorize(): bool
    {
        return $this->authorizePaymentAccess('payments.transaction.register');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
        ];
    }
}
