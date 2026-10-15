<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Core\Payments\Http\Requests\Concerns\AuthorizesPaymentDocuments;

class SubmitPaymentDocumentRequest extends FormRequest
{
    use AuthorizesPaymentDocuments;

    public function authorize(): bool
    {
        return $this->authorizePaymentAccess('payments.invoice.issue');
    }

    public function rules(): array
    {
        return [
            'budget_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
