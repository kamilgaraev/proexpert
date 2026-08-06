<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct;

use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Enums\CurrencyCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractPerformanceActRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $this->attributes->get('current_organization');
        $context = [
            'organization_id' => (int) (
                $organization?->id
                ?? $this->attributes->get('current_organization_id')
                ?? $user?->current_organization_id
            ),
        ];
        if ($this->route('project') !== null) {
            $context['project_id'] = (int) $this->route('project');
        }

        return $user !== null
            && app(AuthorizationService::class)->can($user, 'contracts.performance_acts.edit', $context);
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['prohibited'],
            'organization_id_for_show' => ['prohibited'],
            'project_id' => ['prohibited'],
            'act_document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'act_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_approved' => ['sometimes', 'boolean'],
            'approval_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'required_if:is_approved,true'],
            'currency' => ['sometimes', 'required_if:is_approved,true', Rule::enum(CurrencyCode::class)],

            // Сумма акта
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],

            // Выполненные работы - ОБЯЗАТЕЛЬНЫ при обновлении акта
            'completed_works' => ['sometimes', 'array'],
            'completed_works.*.completed_work_id' => ['required_with:completed_works', 'integer', 'exists:completed_works,id'],
            'completed_works.*.included_quantity' => ['required_with:completed_works', 'numeric', 'decimal:0,3', 'min:0.001'],
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
            act_date: $validatedData['act_date'] ?? '',
            description: $validatedData['description'] ?? null,
            is_approved: $this->has('is_approved') ? $this->boolean('is_approved') : true, // true по умолчанию, если не передано
            approval_date: $validatedData['approval_date'] ?? null,
            completed_works: $validatedData['completed_works'] ?? [],
            amount: $validatedData['amount'] ?? 0, // Используем переданную сумму или 0 по умолчанию
            currency: $validatedData['currency'] ?? null,
            completedWorksProvided: $this->has('completed_works'),
            partialUpdate: true,
            providedFields: array_keys($validatedData),
        );
    }
}
