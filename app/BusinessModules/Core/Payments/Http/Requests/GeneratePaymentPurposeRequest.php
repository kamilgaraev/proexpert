<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GeneratePaymentPurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.create', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string'],
            'data' => ['required', 'array'],
        ];
    }
}
