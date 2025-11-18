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
            
            // Сумма акта (если нет работ - обязательна)
            'amount' => ['nullable', 'numeric', 'min:0'],

            // PDF файл акта (скан) - можно вместо ручного ввода работ
            'pdf_file' => ['required_without:completed_works', 'file', 'mimes:pdf', 'max:10240'], // max 10MB

            // Выполненные работы - можно вместо PDF файла
            'completed_works' => ['required_without:pdf_file', 'array', 'min:1'],
            'completed_works.*.completed_work_id' => ['required', 'integer', 'exists:completed_works,id'],
            'completed_works.*.included_quantity' => ['required', 'numeric', 'min:0'],
            'completed_works.*.included_amount' => ['required', 'numeric', 'min:0'],
            'completed_works.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'pdf_file.required_without' => 'Необходимо либо загрузить PDF файл акта, либо добавить выполненные работы вручную',
            'completed_works.required_without' => 'Необходимо либо добавить выполненные работы вручную, либо загрузить PDF файл акта',
            'pdf_file.mimes' => 'Файл должен быть в формате PDF',
            'pdf_file.max' => 'Размер файла не должен превышать 10 МБ',
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
            completed_works: $this->validated('completed_works', []),
            amount: $this->validated('amount', 0), // Используем переданную сумму или 0 по умолчанию
            pdf_file: $this->file('pdf_file') // PDF файл акта (если загружен)
        );
    }
} 