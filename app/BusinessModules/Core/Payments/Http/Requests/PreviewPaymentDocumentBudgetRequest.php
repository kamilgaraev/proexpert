<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewPaymentDocumentBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.transaction.register', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
        ];
    }
}
