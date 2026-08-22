<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct;

use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractPerformanceActDTO;
use Illuminate\Foundation\Http\FormRequest;

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
            'is_approved' => ['prohibited'],
            'approval_date' => ['prohibited'],
            'currency' => ['prohibited'],
            'amount' => ['prohibited'],
            'completed_works' => ['prohibited'],
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
            is_approved: false,
            approval_date: null,
            completed_works: [],
            amount: 0,
            currency: null,
            completedWorksProvided: false,
            partialUpdate: true,
            providedFields: array_keys($validatedData),
        );
    }
}
