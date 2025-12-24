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
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $supplierId = $this->input('supplier_id');
            $contractorId = $this->input('contractor_id');
            $contractCategory = $this->input('contract_category');

            // Если указан supplier_id, проверяем активацию модулей
            if ($supplierId) {
                $organizationId = $this->attributes->get('current_organization_id') 
                    ?? $this->input('organization_id_for_creation');

                if ($organizationId) {
                    $accessController = app(\App\Modules\Core\AccessController::class);

                    if (!$accessController->hasModuleAccess($organizationId, 'procurement')) {
                        $validator->errors()->add(
                            'supplier_id',
                            'Модуль "Управление закупками" не активирован. Активируйте модуль для создания договоров поставки.'
                        );
                    }

                    if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
                        $validator->errors()->add(
                            'supplier_id',
                            'Модуль "Базовое управление складом" не активирован. Он необходим для работы с договорами поставки.'
                        );
                    }
                }
            }

            // Проверка: либо contractor_id, либо supplier_id должен быть заполнен
            // Исключение: если is_self_execution = true, contractor_id опционален (будет заполнен автоматически)
            $isSelfExecution = $this->input('is_self_execution');
            if (!$supplierId && !$contractorId && !$isSelfExecution) {
                $validator->errors()->add(
                    'contractor_id',
                    'Необходимо указать либо подрядчика (contractor_id), либо поставщика (supplier_id), либо установить is_self_execution=true'
                );
            }

            // Нельзя указать оба одновременно
            if ($supplierId && $contractorId) {
                $validator->errors()->add(
                    'supplier_id',
                    'Нельзя указать одновременно подрядчика и поставщика. Укажите либо contractor_id, либо supplier_id.'
                );
            }
        });
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
        
        // Определяем, является ли контракт самоподрядом
        $isSelfExecution = $this->input('is_self_execution') === true || $this->input('is_self_execution') === '1' || $this->input('is_self_execution') === 1;
        
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id', 'required_without:project_ids'],
            // contractor_id обязателен для генподрядчика, опционален для подрядчика (auto-fill) и для самоподряда
            // Для договоров поставки может быть null, если указан supplier_id
            'contractor_id' => $isContractor || $isSelfExecution
                ? ['nullable', 'integer', 'exists:contractors,id']
                : ['required_without:supplier_id', 'integer', 'exists:contractors,id'],
            'is_self_execution' => ['nullable', 'boolean'],
            'supplier_id' => ['nullable', 'required_without:contractor_id', 'integer', 'exists:suppliers,id'],
            'contract_category' => ['nullable', 'string', 'in:work,procurement,service'],
            'parent_contract_id' => ['nullable', 'integer', new ParentContractValid],
            'number' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            // поле type больше не используется
            'subject' => ['nullable', 'string'],
            'work_type_category' => ['nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['nullable', 'string'],
            'is_fixed_amount' => ['nullable', 'boolean'],
            // base_amount обязателен только для контрактов с фиксированной суммой
            'base_amount' => [
                'required_if:is_fixed_amount,true,1',
                'nullable',
                'numeric',
                'min:0',
            ],
            // total_amount опционален, рассчитывается автоматически для фиксированных контрактов
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'gp_percentage' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'gp_calculation_type' => ['nullable', new Enum(GpCalculationTypeEnum::class)],
            'gp_coefficient' => ['nullable', 'numeric', 'min:0'],
            'warranty_retention_calculation_type' => ['nullable', new Enum(GpCalculationTypeEnum::class)],
            'warranty_retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_retention_coefficient' => ['nullable', 'numeric', 'min:0', 'max:1'],
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
            // Мультипроектные контракты
            'is_multi_project' => ['nullable', 'boolean'],
            'project_ids' => ['nullable', 'required_if:is_multi_project,true,1', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            // Временное поле для примера в контроллере, в реальности organization_id не должен приходить из запроса на создание
            'organization_id_for_creation' => ['sometimes', 'integer'] 
        ];
    }

    public function toDto(): ContractDTO
    {
        // Определяем, является ли контракт с фиксированной суммой
        // По умолчанию true для обратной совместимости
        $isFixedAmount = $this->validated('is_fixed_amount') !== false;
        
        // base_amount обязателен только для фиксированных контрактов
        $baseAmount = $this->validated('base_amount') !== null 
            ? (float) $this->validated('base_amount') 
            : null;
        
        // total_amount опционален, рассчитывается автоматически для фиксированных контрактов
        $totalAmount = $this->validated('total_amount') !== null 
            ? (float) $this->validated('total_amount') 
            : null;
        
        // Для фиксированных контрактов: если total_amount не указан, используем base_amount
        if ($isFixedAmount && $totalAmount === null && $baseAmount !== null) {
            $totalAmount = $baseAmount;
        }
        
        // Мультипроектный контракт
        $isMultiProject = $this->validated('is_multi_project') === true;
        $projectIds = $this->validated('project_ids');
        
        return new ContractDTO(
            project_id: $this->validated('project_id'),
            contractor_id: $this->validated('contractor_id'),
            parent_contract_id: $this->validated('parent_contract_id'),
            number: $this->validated('number'),
            date: $this->validated('date') ? \Carbon\Carbon::parse($this->validated('date'))->format('Y-m-d') : null,
            subject: $this->validated('subject'),
            work_type_category: $this->validated('work_type_category') ? ContractWorkTypeCategoryEnum::from($this->validated('work_type_category')) : null,
            payment_terms: $this->validated('payment_terms'),
            base_amount: $baseAmount,
            total_amount: $totalAmount,
            gp_percentage: $this->validated('gp_percentage') !== null ? (float) $this->validated('gp_percentage') : null,
            gp_calculation_type: $this->validated('gp_calculation_type') ? GpCalculationTypeEnum::from($this->validated('gp_calculation_type')) : null,
            gp_coefficient: $this->validated('gp_coefficient') !== null ? (float) $this->validated('gp_coefficient') : null,
            warranty_retention_calculation_type: $this->validated('warranty_retention_calculation_type') ? GpCalculationTypeEnum::from($this->validated('warranty_retention_calculation_type')) : null,
            warranty_retention_percentage: $this->validated('warranty_retention_percentage') !== null ? (float) $this->validated('warranty_retention_percentage') : null,
            warranty_retention_coefficient: $this->validated('warranty_retention_coefficient') !== null ? (float) $this->validated('warranty_retention_coefficient') : null,
            subcontract_amount: $this->validated('subcontract_amount') !== null ? (float) $this->validated('subcontract_amount') : null,
            planned_advance_amount: $this->validated('planned_advance_amount') !== null ? (float) $this->validated('planned_advance_amount') : null,
            actual_advance_amount: $this->validated('actual_advance_amount') !== null ? (float) $this->validated('actual_advance_amount') : null,
            status: ContractStatusEnum::from($this->validated('status')),
            start_date: $this->validated('start_date') ? \Carbon\Carbon::parse($this->validated('start_date'))->format('Y-m-d') : null,
            end_date: $this->validated('end_date') ? \Carbon\Carbon::parse($this->validated('end_date'))->format('Y-m-d') : null,
            notes: $this->validated('notes'),
            advance_payments: $this->validated('advance_payments'),
            is_fixed_amount: $isFixedAmount,
            is_multi_project: $isMultiProject,
            project_ids: $projectIds,
            is_self_execution: $this->validated('is_self_execution') === true,
            supplier_id: $this->validated('supplier_id'),
            contract_category: $this->validated('contract_category')
        );
    }
} 