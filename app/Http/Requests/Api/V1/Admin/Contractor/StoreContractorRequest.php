<?php

namespace App\Http\Requests\Api\V1\Admin\Contractor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\DTOs\Contractor\ContractorDTO;

class StoreContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // return Auth::user()->can('create', \App\Models\Contractor::class);
        return true; // Упрощенная авторизация
    }

    public function rules(): array
    {
        // $organizationId = Auth::user()->organization_id; 
        // Уникальность ИНН и email должна проверяться в сервисе с учетом organization_id
        // Либо здесь можно использовать Rule::unique(tableName)->where(fn ($query) => $query->where('organization_id', $organizationId))
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'], // уникальность в сервисе
            'legal_address' => ['nullable', 'string', 'max:1000'],
            'inn' => ['nullable', 'string', 'max:12'], // уникальность в сервисе, добавить валидацию формата ИНН
            'kpp' => ['nullable', 'string', 'max:9'], // добавить валидацию формата КПП
            'bank_details' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'organization_id_for_creation' => ['sometimes', 'integer'] // Временное поле из контроллера
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