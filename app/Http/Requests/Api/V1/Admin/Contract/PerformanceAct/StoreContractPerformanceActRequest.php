<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\Contract\ContractPerformanceActDTO;

class StoreContractPerformanceActRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Проверка прав на добавление акта к контракту
        // $contract = $this->route('contract'); // Contract model instance
        // return Auth::user()->can('addAct', $contract);
        return true;
    }

    public function rules(): array
    {
        return [
            'act_document_number' => ['nullable', 'string', 'max:255'],
            'act_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_approved' => ['sometimes', 'boolean'],
            'approval_date' => ['nullable', 'date_format:Y-m-d', 'required_if:is_approved,true'],
            'organization_id_for_show' => ['sometimes', 'integer'] // Временное поле
        ];
    }

    public function toDto(): ContractPerformanceActDTO
    {
        return new ContractPerformanceActDTO(
            act_document_number: $this->input('act_document_number'),
            act_date: $this->input('act_date'),
            amount: (float) $this->input('amount'),
            description: $this->input('description'),
            is_approved: $this->boolean('is_approved', true),
            approval_date: $this->input('approval_date')
        );
    }
} 