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
            'act_document_number' => ['nullable', 'string', 'max:100'],
            'act_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_approved' => ['sometimes', 'boolean'],
            'approval_date' => ['nullable', 'date', 'after_or_equal:act_date'],

            // Выполненные работы - ОБЯЗАТЕЛЬНЫ для создания акта
            'completed_works' => ['required', 'array', 'min:1'],
            'completed_works.*.completed_work_id' => ['required', 'integer', 'exists:completed_works,id'],
            'completed_works.*.included_quantity' => ['required', 'numeric', 'min:0'],
            'completed_works.*.included_amount' => ['required', 'numeric', 'min:0'],
            'completed_works.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toDto(): ContractPerformanceActDTO
    {
        return new ContractPerformanceActDTO(
            act_document_number: $this->validated('act_document_number'),
            act_date: $this->validated('act_date'),
            description: $this->validated('description'),
            is_approved: $this->validated('is_approved', true),
            approval_date: $this->validated('approval_date'),
            completed_works: $this->validated('completed_works', [])
        );
    }
} 