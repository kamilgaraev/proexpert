<?php

namespace App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct;

use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Enums\CurrencyCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractPerformanceActRequest extends FormRequest
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
            && app(AuthorizationService::class)->can($user, 'contracts.performance_acts.create', $context);
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['prohibited'],
            'organization_id_for_show' => ['prohibited'],
            'project_id' => ['prohibited'],
            'act_document_number' => ['nullable', 'string', 'max:100'],
            'act_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_approved' => ['prohibited'],
            'approval_date' => ['prohibited'],
            'currency' => ['required', Rule::enum(CurrencyCode::class)],

            'amount' => ['prohibited'],

            // PDF файл акта (скан) - можно вместо ручного ввода работ
            'pdf_file' => ['required_without:completed_works', 'file', 'mimes:pdf', 'max:10240'], // max 10MB

            // Выполненные работы - можно вместо PDF файла
            'completed_works' => ['required_without:pdf_file', 'array', 'min:1'],
            'completed_works.*.completed_work_id' => ['required', 'integer', 'exists:completed_works,id'],
            'completed_works.*.included_quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001'],
            'completed_works.*.included_amount' => ['prohibited'],
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
        // Получаем project_id из маршрута
        $projectId = $this->route('project') ?? null;

        return new ContractPerformanceActDTO(
            project_id: $projectId,
            act_document_number: $this->validated('act_document_number'),
            act_date: $this->validated('act_date'),
            description: $this->validated('description'),
            is_approved: false,
            approval_date: null,
            completed_works: $this->validated('completed_works', []),
            amount: 0,
            pdf_file: $this->file('pdf_file'), // PDF файл акта (если загружен)
            currency: $this->validated('currency'),
            completedWorksProvided: $this->has('completed_works'),
        );
    }
}
