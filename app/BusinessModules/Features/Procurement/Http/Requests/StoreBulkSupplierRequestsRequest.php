<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBulkSupplierRequestsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $suppliers = $this->input('suppliers');

        if (!is_array($suppliers)) {
            return;
        }

        foreach ($suppliers as $index => $supplier) {
            if (!is_array($supplier) || !isset($supplier['external_supplier']) || !is_array($supplier['external_supplier'])) {
                continue;
            }

            foreach (['contact_person', 'phone', 'email', 'tax_number', 'address'] as $field) {
                if (($supplier['external_supplier'][$field] ?? null) === 'null') {
                    $supplier['external_supplier'][$field] = null;
                }
            }

            $suppliers[$index] = $supplier;
        }

        $this->merge([
            'suppliers' => $suppliers,
        ]);
    }

    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        if (!$user || $organizationId <= 0) {
            return false;
        }

        return app(AuthorizationService::class)->can($user, 'procurement.supplier_requests.create', [
            'organization_id' => $organizationId,
        ]);
    }

    public function rules(): array
    {
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return [
            'purchase_request_id' => [
                'required',
                'integer',
                Rule::exists('purchase_requests', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
            'suppliers' => ['required', 'array', 'min:1', 'max:20'],
            'suppliers.*.supplier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'suppliers.*.external_supplier' => ['sometimes', 'nullable', 'array'],
            'suppliers.*.external_supplier.name' => ['required_with:suppliers.*.external_supplier', 'string', 'max:255'],
            'suppliers.*.external_supplier.contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'suppliers.*.external_supplier.phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'suppliers.*.external_supplier.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'suppliers.*.external_supplier.tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'suppliers.*.external_supplier.address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'suppliers.*.external_supplier.metadata' => ['sometimes', 'array'],
            'send_immediately' => ['sometimes', 'boolean'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $suppliers = $this->input('suppliers', []);

            if (!is_array($suppliers)) {
                return;
            }

            foreach ($suppliers as $index => $supplier) {
                if (!is_array($supplier)) {
                    continue;
                }

                $hasRegisteredSupplier = filled($supplier['supplier_id'] ?? null);
                $hasExternalSupplier = isset($supplier['external_supplier']) && is_array($supplier['external_supplier']);

                if ($hasRegisteredSupplier === $hasExternalSupplier) {
                    $validator->errors()->add(
                        "suppliers.{$index}",
                        trans_message('procurement.supplier_requests.single_supplier_source_required')
                    );
                }

                if (
                    $hasExternalSupplier
                    && trim((string) ($supplier['external_supplier']['name'] ?? '')) === ''
                ) {
                    $validator->errors()->add(
                        "suppliers.{$index}.external_supplier.name",
                        trans_message('procurement.supplier_requests.external_supplier_name_required')
                    );
                }
            }
        });
    }
}
