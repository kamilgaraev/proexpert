<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contractor;

use App\DTOs\Contractor\ContractorDTO;
use Illuminate\Foundation\Http\FormRequest;

class StoreContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'legal_address' => ['nullable', 'string', 'max:1000'],
            'inn' => ['nullable', 'string', 'max:12'],
            'kpp' => ['nullable', 'string', 'max:9'],
            'bank_details' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'organization_id_for_creation' => ['sometimes', 'integer'],
        ];
    }

    public function toDto(): ContractorDTO
    {
        return new ContractorDTO(
            name: $this->validated('name'),
            contact_person: $this->validated('contact_person'),
            phone: $this->validated('phone'),
            email: $this->validated('email'),
            legal_address: $this->validated('legal_address'),
            inn: $this->validated('inn'),
            kpp: $this->validated('kpp'),
            bank_details: $this->validated('bank_details'),
            notes: $this->validated('notes')
        );
    }
}
