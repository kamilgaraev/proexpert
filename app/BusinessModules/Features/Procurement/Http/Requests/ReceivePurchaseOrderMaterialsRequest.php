<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReceivePurchaseOrderMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_mode' => $this->input('document_mode', 'torg12_paper'),
        ]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');
        $purchaseOrderId = (int) $this->route('id');

        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('organization_warehouses', 'id')->where(static function ($query) use ($organizationId): void {
                    $query->where('organization_id', $organizationId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('purchase_order_items', 'id')->where(static function ($query) use ($purchaseOrderId): void {
                    $query->where('purchase_order_id', $purchaseOrderId);
                }),
            ],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0.001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.metadata' => ['sometimes', 'array'],
            'receipt_date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'metadata' => ['sometimes', 'array'],
            'idempotency_key' => ['required', 'uuid'],
            'document_mode' => ['required', Rule::in(['upd_xml', 'torg12_paper'])],
            'receipt_document_id' => [
                'nullable',
                'integer',
                'required_if:document_mode,upd_xml',
                'prohibited_unless:document_mode,upd_xml',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document_mode.in' => trans_message('procurement.upd.validation.document_mode_invalid'),
            'receipt_document_id.required_if' => trans_message('procurement.upd.validation.document_required'),
            'receipt_document_id.prohibited_unless' => trans_message('procurement.upd.validation.document_not_allowed'),
        ];
    }
}
