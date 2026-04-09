<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Rules\ParentContractValid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $sideType = ContractSideTypeEnum::tryFrom((string) $this->input('contract_side_type'));
            $supplierId = $this->input('supplier_id');
            $contractorId = $this->input('contractor_id');
            $isSelfExecution = $this->boolean('is_self_execution');

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

            if (!$sideType) {
                return;
            }

            if ($sideType === ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER) {
                if (!$supplierId) {
                    $validator->errors()->add('supplier_id', 'Для этого типа договора нужно выбрать поставщика.');
                }

                if ($contractorId) {
                    $validator->errors()->add('contractor_id', 'Для договора с поставщиком подрядчик не заполняется.');
                }

                if ($isSelfExecution) {
                    $validator->errors()->add('is_self_execution', 'Для договора с поставщиком нельзя включать собственные силы.');
                }
            }

            if ($sideType === ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR) {
                if (!$contractorId && !$isSelfExecution) {
                    $validator->errors()->add('contractor_id', 'Для этого типа договора нужно выбрать подрядчика или включить собственные силы.');
                }

                if ($supplierId) {
                    $validator->errors()->add('supplier_id', 'Для договора с подрядчиком поставщик не заполняется.');
                }
            }

            if ($sideType === ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR) {
                if (!$contractorId) {
                    $validator->errors()->add('contractor_id', 'Для этого типа договора нужно выбрать субподрядчика.');
                }

                if ($supplierId) {
                    $validator->errors()->add('supplier_id', 'Для договора с субподрядчиком поставщик не заполняется.');
                }

                if ($isSelfExecution) {
                    $validator->errors()->add('is_self_execution', 'Для договора с субподрядчиком нельзя включать собственные силы.');
                }
            }

            if ($sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR) {
                if ($supplierId) {
                    $validator->errors()->add('supplier_id', 'Для договора между заказчиком и генподрядчиком поставщик не заполняется.');
                }

                if ($isSelfExecution) {
                    $validator->errors()->add('is_self_execution', 'Для договора между заказчиком и генподрядчиком нельзя включать собственные силы.');
                }
            }
        });
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'exists:projects,id', 'required_without:project_ids'],
            'contract_side_type' => ['required', new Enum(ContractSideTypeEnum::class)],
            'contractor_id' => ['nullable', 'integer', 'exists:contractors,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'is_self_execution' => ['nullable', 'boolean'],
            'contract_category' => ['nullable', 'string', 'in:work,procurement,service'],
            'parent_contract_id' => ['nullable', 'integer', new ParentContractValid],
            'number' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'subject' => ['nullable', 'string'],
            'work_type_category' => ['nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['nullable', 'string'],
            'is_fixed_amount' => ['nullable', 'boolean'],
            'base_amount' => ['required_if:is_fixed_amount,true,1', 'nullable', 'numeric', 'min:0'],
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
            'is_multi_project' => ['nullable', 'boolean'],
            'project_ids' => ['nullable', 'required_if:is_multi_project,true,1', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            'organization_id_for_creation' => ['sometimes', 'integer'],
        ];
    }

    public function toDto(): ContractDTO
    {
        $isFixedAmount = $this->validated('is_fixed_amount') !== false;
        $baseAmount = $this->validated('base_amount') !== null ? (float) $this->validated('base_amount') : null;
        $totalAmount = $this->validated('total_amount') !== null ? (float) $this->validated('total_amount') : null;

        if ($isFixedAmount && $totalAmount === null && $baseAmount !== null) {
            $totalAmount = $baseAmount;
        }

        $isMultiProject = $this->validated('is_multi_project') === true;

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
            project_ids: $this->validated('project_ids'),
            is_self_execution: $this->boolean('is_self_execution'),
            supplier_id: $this->validated('supplier_id'),
            contract_category: $this->validated('contract_category'),
            contract_side_type: $this->validated('contract_side_type')
                ? ContractSideTypeEnum::from($this->validated('contract_side_type'))
                : null
        );
    }
}
