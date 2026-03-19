<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = $this->getCurrentOrganizationId();

        return $organizationId > 0
            && (bool) $this->user()?->can('payments.invoice.create', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        $organizationId = $this->getCurrentOrganizationId();
        $estimateId = $this->integer('estimate_id');

        $isAdvanceWithContract = $this->input('invoice_type') === 'advance'
            && (
                ($this->input('source_type') === 'App\\Models\\Contract' && $this->input('source_id'))
                || ($this->input('invoiceable_type') === 'App\\Models\\Contract' && $this->input('invoiceable_id'))
                || $this->input('contract_id')
            );

        return [
            'document_type' => 'required|string|in:payment_request,invoice,payment_order,incoming_payment,expense,offset_act',
            'document_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'payer_organization_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id'),
            ],
            'payer_contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'payee_organization_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id'),
            ],
            'payee_contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
            ],
            'amount' => $isAdvanceWithContract ? 'nullable|numeric|min:0' : 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'source_type' => [
                'nullable',
                'string',
                Rule::in([
                    \App\Models\Contract::class,
                    \App\Models\ContractPerformanceAct::class,
                    \App\BusinessModules\Core\Payments\Models\PaymentDocument::class,
                ]),
            ],
            'source_id' => 'nullable|integer',
            'invoiceable_type' => [
                'nullable',
                'string',
                Rule::in([
                    \App\Models\Contract::class,
                    \App\Models\ContractPerformanceAct::class,
                ]),
            ],
            'invoiceable_id' => 'nullable|integer',
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where('organization_id', $organizationId),
            ],
            'estimate_id' => [
                'nullable',
                'integer',
                Rule::exists('estimates', 'id')->where('organization_id', $organizationId),
            ],
            'estimate_splits' => 'nullable|array',
            'estimate_splits.*.estimate_item_id' => [
                'required_with:estimate_splits',
                'integer',
                Rule::exists('estimate_items', 'id')->where(function ($query) use ($organizationId, $estimateId) {
                    $query->whereNull('deleted_at')
                        ->whereExists(function ($estimateQuery) use ($organizationId, $estimateId) {
                            $estimateQuery->selectRaw('1')
                                ->from('estimates')
                                ->whereColumn('estimates.id', 'estimate_items.estimate_id')
                                ->where('estimates.organization_id', $organizationId)
                                ->when($estimateId > 0, fn ($builder) => $builder->where('estimates.id', $estimateId));
                        });
                }),
            ],
            'estimate_splits.*.quantity' => 'required_with:estimate_splits|numeric|min:0',
            'estimate_splits.*.unit_price_actual' => 'required_with:estimate_splits|numeric|min:0',
            'estimate_splits.*.amount' => 'nullable|numeric|min:0',
            'estimate_splits.*.percentage' => 'nullable|numeric|min:0|max:100',
            'invoice_type' => 'nullable|string|in:act,advance,progress,final,material_purchase,service,equipment,salary,other',
            'direction' => 'nullable|string|in:incoming,outgoing',
            'description' => 'nullable|string',
            'payment_purpose' => 'nullable|string',
            'overprice_justification' => 'nullable|string|max:1000',
            'bank_account' => 'nullable|string|size:20',
            'bank_bik' => 'nullable|string|size:9',
            'bank_correspondent_account' => 'nullable|string|size:20',
            'bank_name' => 'nullable|string',
            'attached_documents' => 'nullable|array',
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'Тип документа обязателен',
            'document_type.in' => 'Недопустимый тип документа',
            'amount.required' => 'Сумма обязательна',
            'amount.numeric' => 'Сумма должна быть числом',
            'amount.min' => 'Сумма должна быть не менее 0.01',
            'vat_rate.min' => 'Ставка НДС не может быть отрицательной',
            'vat_rate.max' => 'Ставка НДС не может превышать 100%',
            'currency.size' => 'Код валюты должен состоять из 3 символов',
            'bank_account.size' => 'Банковский счет должен состоять из 20 символов',
            'bank_bik.size' => 'БИК должен состоять из 9 символов',
            'bank_correspondent_account.size' => 'Корреспондентский счет должен состоять из 20 символов',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('amount')) {
            $this->merge([
                'amount' => $this->convertToNumber($this->amount),
            ]);
        }

        if ($this->has('vat_rate')) {
            $this->merge([
                'vat_rate' => $this->convertToNumber($this->vat_rate),
            ]);
        }
    }

    private function getCurrentOrganizationId(): int
    {
        return (int) $this->attributes->get('current_organization_id', 0);
    }

    private function convertToNumber(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $value = str_replace(' ', '', $value);

        if (strpos($value, '.') !== false && strpos($value, ',') !== false) {
            $lastDot = strrpos($value, '.');
            $lastComma = strrpos($value, ',');

            if ($lastDot > $lastComma) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        } elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : $value;
    }
}
