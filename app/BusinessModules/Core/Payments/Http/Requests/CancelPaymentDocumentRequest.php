<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\BusinessModules\Core\Payments\Http\Requests\Concerns\AuthorizesPaymentDocuments;

final class CancelPaymentDocumentRequest extends FormRequest
{
    use AuthorizesPaymentDocuments;

    public function authorize(): bool
    {
        return $this->authorizePaymentAccess('payments.invoice.cancel');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3'],
        ];
    }
}
