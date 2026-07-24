<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelPaymentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.cancel', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3'],
        ];
    }
}
