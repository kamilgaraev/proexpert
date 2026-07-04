<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Counterparty;

use App\DTOs\Counterparty\CounterpartyData;
use App\Enums\CounterpartyRoleEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreCounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:12'],
            'kpp' => ['nullable', 'string', 'max:9'],
            'ogrn' => ['nullable', 'string', 'max:15'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'legal_address' => ['nullable', 'string', 'max:1000'],
            'postal_address' => ['nullable', 'string', 'max:1000'],
            'bank_details' => ['nullable', 'array'],
            'roles' => ['nullable', 'array'],
            'roles.*' => [new Enum(CounterpartyRoleEnum::class)],
            'linked_organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toDto(): CounterpartyData
    {
        $validatedData = $this->validated();

        return new CounterpartyData(
            name: $validatedData['name'],
            legalName: $validatedData['legal_name'] ?? null,
            inn: $validatedData['inn'] ?? null,
            kpp: $validatedData['kpp'] ?? null,
            ogrn: $validatedData['ogrn'] ?? null,
            email: $validatedData['email'] ?? null,
            phone: $validatedData['phone'] ?? null,
            contactPerson: $validatedData['contact_person'] ?? null,
            legalAddress: $validatedData['legal_address'] ?? null,
            postalAddress: $validatedData['postal_address'] ?? null,
            bankDetails: $validatedData['bank_details'] ?? null,
            roles: $validatedData['roles'] ?? null,
            linkedOrganizationId: $validatedData['linked_organization_id'] ?? null,
            isActive: $validatedData['is_active'] ?? true,
        );
    }
}
