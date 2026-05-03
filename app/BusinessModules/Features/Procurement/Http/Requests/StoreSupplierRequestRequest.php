<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequestRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $externalSupplier = $this->input('external_supplier');

        if (!is_array($externalSupplier)) {
            return;
        }

        foreach (['contact_person', 'phone', 'email', 'tax_number', 'address'] as $field) {
            if (($externalSupplier[$field] ?? null) === 'null') {
                $externalSupplier[$field] = null;
            }
        }

        $this->merge([
            'external_supplier' => $externalSupplier,
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
            'supplier_id' => [
                'required_without:external_supplier',
                Rule::prohibitedIf(fn (): bool => $this->has('external_supplier')),
                'integer',
                Rule::exists('suppliers', 'id')->where(static function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'external_supplier' => [
                'required_without:supplier_id',
                Rule::prohibitedIf(fn (): bool => $this->filled('supplier_id')),
                'array',
            ],
            'external_supplier.name' => ['required_with:external_supplier', 'string', 'max:255'],
            'external_supplier.contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'external_supplier.phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'external_supplier.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'external_supplier.tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'external_supplier.address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'external_supplier.metadata' => ['sometimes', 'array'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
