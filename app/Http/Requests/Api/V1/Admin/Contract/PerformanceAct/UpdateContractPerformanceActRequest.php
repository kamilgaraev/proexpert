<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct;

use Illuminate\Foundation\Http\FormRequest;
use App\DTOs\Contract\ContractPerformanceActDTO;

class UpdateContractPerformanceActRequest extends FormRequest
{
    public function authorize(): bool
    {
        // $act = $this->route('performanceAct'); // Route model binding
        // return Auth::user()->can('update', $act);
        return true;
    }

    public function rules(): array
    {
        return [
            'act_document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'act_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_approved' => ['sometimes', 'boolean'],
            'approval_date' => ['sometimes','nullable', 'date_format:Y-m-d', 'required_if:is_approved,true'],
            'organization_id_for_show' => ['sometimes', 'integer'], // Временное поле
            
            // Сумма акта
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Выполненные работы - ОБЯЗАТЕЛЬНЫ при обновлении акта
            'completed_works' => ['sometimes', 'array'],
            'completed_works.*.completed_work_id' => ['required_with:completed_works', 'integer', 'exists:completed_works,id'],
            'completed_works.*.included_quantity' => ['required_with:completed_works', 'numeric', 'min:0'],
            'completed_works.*.included_amount' => ['required_with:completed_works', 'numeric', 'min:0'],
            'completed_works.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toDto(): ContractPerformanceActDTO
    {
        // Важно: DTO ожидает все поля. Если поле не пришло, оно не будет передано в конструктор DTO.
        // Это может быть проблемой, если конструктор DTO не имеет значений по умолчанию или не nullable.
        // ContractPerformanceActDTO имеет значения по умолчанию/nullable для большинства полей.
        // $this->input() вернет null, если поле отсутствует, что совместимо с DTO.
        $validatedData = $this->validated(); // Получаем только валидированные данные

        // Получаем project_id из маршрута
        $projectId = $this->route('project') ?? null;

        return new ContractPerformanceActDTO(
            project_id: $projectId,
            act_document_number: $validatedData['act_document_number'] ?? null,
            act_date: $validatedData['act_date'], // 'required' if present
            description: $validatedData['description'] ?? null,
            is_approved: $this->has('is_approved') ? $this->boolean('is_approved') : true, // true по умолчанию, если не передано
            approval_date: $validatedData['approval_date'] ?? null,
            completed_works: $validatedData['completed_works'] ?? [],
            amount: $validatedData['amount'] ?? 0 // Используем переданную сумму или 0 по умолчанию
        );
    }
} 