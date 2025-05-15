<?php

namespace App\Http\Requests\Api\V1\Admin\Contractor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\Contractor\ContractorDTO;

class UpdateContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // $contractor = $this->route('contractor'); // Если Route Model Binding
        // return Auth::user()->can('update', $contractor);
        return true; // Упрощенная авторизация
    }

    public function rules(): array
    {
        // $organizationId = Auth::user()->organization_id;
        // Уникальность ИНН и email с исключением текущей записи должна проверяться в сервисе.
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
            'organization_id_for_update' => ['sometimes', 'integer'] // Временное поле
        ];
    }

    public function toDto(): ContractorDTO
    {
        $validatedData = $this->validated();
        return new ContractorDTO(
            name: $validatedData['name'], 
            contact_person: $validatedData['contact_person'] ?? null,
            phone: $validatedData['phone'] ?? null,
            email: $validatedData['email'] ?? null,
            legal_address: $validatedData['legal_address'] ?? null,
            inn: $validatedData['inn'] ?? null,
            kpp: $validatedData['kpp'] ?? null,
            bank_details: $validatedData['bank_details'] ?? null,
            notes: $validatedData['notes'] ?? null
        );
    }
} 