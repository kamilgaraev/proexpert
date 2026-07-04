<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Counterparty;

use App\DTOs\Counterparty\CounterpartyData;
use App\Enums\CounterpartyRoleEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateCounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inn' => ['sometimes', 'nullable', 'string', 'max:12'],
            'kpp' => ['sometimes', 'nullable', 'string', 'max:9'],
            'ogrn' => ['sometimes', 'nullable', 'string', 'max:15'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'postal_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'bank_details' => ['sometimes', 'nullable', 'array'],
            'roles' => ['sometimes', 'nullable', 'array'],
            'roles.*' => [new Enum(CounterpartyRoleEnum::class)],
            'linked_organization_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toDto(): CounterpartyData
    {
        $validatedData = $this->validated();

        return new CounterpartyData(
            name: $validatedData['name'] ?? '',
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
            providedFields: array_keys($validatedData),
        );
    }
}
