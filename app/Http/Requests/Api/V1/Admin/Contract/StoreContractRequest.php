<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\DTOs\Contract\ContractDTO;
use Illuminate\Validation\Rules\Enum;
use App\Rules\ParentContractValid;

class StoreContractRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // return Auth::user()->can('create', \App\Models\Contract::class);
        return true; // Упрощенная авторизация для примера
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        // Определяем, является ли организация подрядчиком
        $projectContext = \App\Http\Middleware\ProjectContextMiddleware::getProjectContext($this);
        $isContractor = $projectContext && in_array($projectContext->role->value, ['contractor', 'subcontractor']);
        
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            // contractor_id обязателен для генподрядчика, опционален для подрядчика (auto-fill)
            'contractor_id' => $isContractor 
                ? ['nullable', 'integer', 'exists:contractors,id']
                : ['required', 'integer', 'exists:contractors,id'],
            'parent_contract_id' => ['nullable', 'integer', new ParentContractValid],
            'number' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            // поле type больше не используется
            'subject' => ['nullable', 'string'],
            'work_type_category' => ['nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['nullable', 'string'],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'gp_percentage' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'gp_calculation_type' => ['nullable', new Enum(GpCalculationTypeEnum::class)],
            'gp_coefficient' => ['nullable', 'numeric', 'min:0'],
            'subcontract_amount' => ['nullable', 'numeric', 'min:0'],
            'planned_advance_amount' => ['nullable', 'numeric', 'min:0'],
            'actual_advance_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_payments' => ['nullable', 'array'],
            'advance_payments.*.amount' => ['required', 'numeric', 'min:0'],
            'advance_payments.*.payment_date' => ['nullable', 'date'],
            'advance_payments.*.description' => ['nullable', 'string'],
            'status' => ['required', new Enum(ContractStatusEnum::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            // Временное поле для примера в контроллере, в реальности organization_id не должен приходить из запроса на создание
            'organization_id_for_creation' => ['sometimes', 'integer'] 
        ];
    }

    public function toDto(): ContractDTO
    {
        // Логика для base_amount и total_amount:
        // Если передан base_amount - используем его как базовую сумму
        // Иначе используем total_amount как базовую (для legacy совместимости)
        $baseAmount = $this->validated('base_amount');
        $totalAmount = $this->validated('total_amount');
        
        // Приоритет: base_amount > total_amount
        $finalBaseAmount = $baseAmount !== null ? (float) $baseAmount : (float) $totalAmount;
        
        return new ContractDTO(
            project_id: $this->validated('project_id'),
            contractor_id: $this->validated('contractor_id'),
            parent_contract_id: $this->validated('parent_contract_id'),
            number: $this->validated('number'),
            date: $this->validated('date') ? \Carbon\Carbon::parse($this->validated('date'))->format('Y-m-d') : null,
            subject: $this->validated('subject'),
            work_type_category: $this->validated('work_type_category') ? ContractWorkTypeCategoryEnum::from($this->validated('work_type_category')) : null,
            payment_terms: $this->validated('payment_terms'),
            base_amount: $finalBaseAmount,
            total_amount: (float) $totalAmount,
            gp_percentage: $this->validated('gp_percentage') !== null ? (float) $this->validated('gp_percentage') : null,
            gp_calculation_type: $this->validated('gp_calculation_type') ? GpCalculationTypeEnum::from($this->validated('gp_calculation_type')) : null,
            gp_coefficient: $this->validated('gp_coefficient') !== null ? (float) $this->validated('gp_coefficient') : null,
            subcontract_amount: $this->validated('subcontract_amount') !== null ? (float) $this->validated('subcontract_amount') : null,
            planned_advance_amount: $this->validated('planned_advance_amount') !== null ? (float) $this->validated('planned_advance_amount') : null,
            actual_advance_amount: $this->validated('actual_advance_amount') !== null ? (float) $this->validated('actual_advance_amount') : null,
            status: ContractStatusEnum::from($this->validated('status')),
            start_date: $this->validated('start_date') ? \Carbon\Carbon::parse($this->validated('start_date'))->format('Y-m-d') : null,
            end_date: $this->validated('end_date') ? \Carbon\Carbon::parse($this->validated('end_date'))->format('Y-m-d') : null,
            notes: $this->validated('notes'),
            advance_payments: $this->validated('advance_payments')
        );
    }
} 