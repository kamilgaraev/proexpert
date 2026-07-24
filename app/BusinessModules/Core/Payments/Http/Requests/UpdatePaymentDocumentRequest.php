<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePaymentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.edit', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id', 0);

        return [
            'document_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'budget_article_id' => ['sometimes', 'nullable'],
            'responsibility_center_id' => ['sometimes', 'nullable'],
            'budget_override_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'payment_purpose' => ['sometimes', 'nullable', 'string'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'size:20'],
            'bank_bik' => ['sometimes', 'nullable', 'string', 'size:9'],
            'bank_correspondent_account' => ['sometimes', 'nullable', 'string', 'size:20'],
            'bank_name' => ['sometimes', 'nullable', 'string'],
            'attached_documents' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
