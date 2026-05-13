<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contractor;

use App\DTOs\Contractor\ContractorDTO;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'legal_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'inn' => ['sometimes', 'nullable', 'string', 'max:12'],
            'kpp' => ['sometimes', 'nullable', 'string', 'max:9'],
            'bank_details' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'organization_id_for_update' => ['sometimes', 'integer'],
        ];
    }

    public function toDto(): ContractorDTO
    {
        $validatedData = $this->validated();

        return new ContractorDTO(
            name: $validatedData['name'] ?? null,
            contact_person: $validatedData['contact_person'] ?? null,
            phone: $validatedData['phone'] ?? null,
            email: $validatedData['email'] ?? null,
            legal_address: $validatedData['legal_address'] ?? null,
            inn: $validatedData['inn'] ?? null,
            kpp: $validatedData['kpp'] ?? null,
            bank_details: $validatedData['bank_details'] ?? null,
            notes: $validatedData['notes'] ?? null,
            providedFields: array_keys($validatedData)
        );
    }
}
