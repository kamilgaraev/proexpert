<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

use function trans_message;

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
        $isActInvoice = $this->input('invoiceable_type') === ContractPerformanceAct::class
            && $this->input('invoiceable_id');

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
            'budget_article_id' => ['nullable'],
            'responsibility_center_id' => ['nullable'],
            'budget_override_reason' => ['nullable', 'string', 'max:1000'],
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
            'idempotency_key' => [
                Rule::requiredIf((bool) $isActInvoice),
                'nullable',
                'string',
                'min:16',
                'max:128',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateScopedMorphReference($validator, 'source_type', 'source_id');
                $this->validateScopedMorphReference($validator, 'invoiceable_type', 'invoiceable_id');
            },
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => trans_message('payments.validation.document_type_required'),
            'document_type.in' => trans_message('payments.validation.document_type_invalid'),
            'amount.required' => trans_message('payments.validation.amount_required'),
            'amount.numeric' => trans_message('payments.validation.amount_numeric'),
            'amount.min' => trans_message('payments.validation.amount_minimum'),
            'vat_rate.min' => trans_message('payments.validation.vat_rate_minimum'),
            'vat_rate.max' => trans_message('payments.validation.vat_rate_maximum'),
            'currency.size' => trans_message('payments.validation.currency_size'),
            'bank_account.size' => trans_message('payments.validation.bank_account_size'),
            'bank_bik.size' => trans_message('payments.validation.bank_bik_size'),
            'bank_correspondent_account.size' => trans_message('payments.validation.bank_correspondent_account_size'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $idempotencyKey = trim((string) $this->header('Idempotency-Key'));
        if ($idempotencyKey !== '') {
            $this->merge(['idempotency_key' => $idempotencyKey]);
        }

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

    private function validateScopedMorphReference(Validator $validator, string $typeField, string $idField): void
    {
        $type = $this->input($typeField);
        $id = $this->input($idField);

        if (! is_string($type) || $type === '' || $id === null || $id === '') {
            return;
        }

        if (! is_numeric($id) || ! $this->morphReferenceExists($type, (int) $id)) {
            $validator->errors()->add($idField, trans_message('payments.validation.source_not_found'));
        }
    }

    private function morphReferenceExists(string $type, int $id): bool
    {
        $organizationId = $this->getCurrentOrganizationId();

        return match ($type) {
            Contract::class => Contract::query()
                ->whereKey($id)
                ->where('organization_id', $organizationId)
                ->exists(),
            ContractPerformanceAct::class => ContractPerformanceAct::query()
                ->whereKey($id)
                ->whereHas('contract', fn ($query) => $query->where('organization_id', $organizationId))
                ->exists(),
            PaymentDocument::class => PaymentDocument::query()
                ->whereKey($id)
                ->where('organization_id', $organizationId)
                ->exists(),
            default => false,
        };
    }

    private function convertToNumber(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
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

        return $value;
    }
}
