<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use App\Rules\ParentContractValid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $contract = $this->resolveContract();
            $sideType = ContractSideTypeEnum::tryFrom((string) ($this->input('contract_side_type') ?: $contract?->contract_side_type?->value));
            $supplierId = $this->input('supplier_id', $contract?->supplier_id);
            $contractorId = $this->input('contractor_id', $contract?->contractor_id);
            $isSelfExecution = $this->has('is_self_execution')
                ? $this->boolean('is_self_execution')
                : (bool) $contract?->is_self_execution;

            if ($supplierId) {
                $organizationId = $this->attributes->get('current_organization_id')
                    ?? $contract?->organization_id;

                if ($organizationId) {
                    $accessController = app(\App\Modules\Core\AccessController::class);

                    if (!$accessController->hasModuleAccess($organizationId, 'procurement')) {
                        $validator->errors()->add('supplier_id', 'Модуль "Управление закупками" не активирован.');
                    }

                    if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
                        $validator->errors()->add('supplier_id', 'Модуль "Базовое управление складом" не активирован.');
                    }
                }
            }

            if (!$sideType) {
                return;
            }

            if ($sideType->requiresSupplier()) {
                if (!$supplierId) {
                    $validator->errors()->add('supplier_id', 'Для этого типа договора нужно выбрать поставщика.');
                }

                if ($contractorId) {
                    $validator->errors()->add('contractor_id', 'Для договора поставки подрядчик не заполняется.');
                }

                if ($isSelfExecution) {
                    $validator->errors()->add('is_self_execution', 'Для договора поставки нельзя включать собственные силы.');
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
                    $validator->errors()->add('supplier_id', 'Для договора между заказчиком и исполнителем по проекту поставщик не заполняется.');
                }

                if ($isSelfExecution) {
                    $validator->errors()->add('is_self_execution', 'Для договора между заказчиком и исполнителем по проекту нельзя включать собственные силы.');
                }
            }
        });
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'contract_side_type' => ['sometimes', new Enum(ContractSideTypeEnum::class)],
            'contractor_id' => ['sometimes', 'nullable', 'integer', 'exists:contractors,id'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'is_self_execution' => ['sometimes', 'nullable', 'boolean'],
            'contract_category' => ['sometimes', 'nullable', 'string', 'in:work,procurement,service'],
            'parent_contract_id' => ['sometimes', 'nullable', 'integer', new ParentContractValid],
            'number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date' => ['sometimes', 'nullable', 'date'],
            'subject' => ['sometimes', 'nullable', 'string'],
            'work_type_category' => ['sometimes', 'nullable', new Enum(ContractWorkTypeCategoryEnum::class)],
            'payment_terms' => ['sometimes', 'nullable', 'string'],
            'is_fixed_amount' => ['sometimes', 'nullable', 'boolean'],
            'base_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'gp_percentage' => ['sometimes', 'nullable', 'numeric', 'min:-100', 'max:100'],
            'gp_calculation_type' => ['sometimes', 'nullable', new Enum(GpCalculationTypeEnum::class)],
            'gp_coefficient' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'warranty_retention_calculation_type' => ['sometimes', 'nullable', new Enum(GpCalculationTypeEnum::class)],
            'warranty_retention_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_retention_coefficient' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'subcontract_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'planned_advance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'actual_advance_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'nullable', new Enum(ContractStatusEnum::class)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_multi_project' => ['sometimes', 'nullable', 'boolean'],
            'project_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
        ];
    }

    protected function prepareForValidation()
    {
        $input = $this->all();

        Log::info('UpdateContractRequest::prepareForValidation - RAW INPUT', [
            'contract_id' => $this->route('contract'),
            'input_keys' => array_keys($input),
            'input_count' => count($input),
        ]);

        $allowedFields = [
            'project_id',
            'contract_side_type',
            'contractor_id',
            'supplier_id',
            'parent_contract_id',
            'number',
            'date',
            'subject',
            'work_type_category',
            'payment_terms',
            'is_fixed_amount',
            'base_amount',
            'total_amount',
            'gp_percentage',
            'gp_calculation_type',
            'gp_coefficient',
            'warranty_retention_calculation_type',
            'warranty_retention_percentage',
            'warranty_retention_coefficient',
            'subcontract_amount',
            'planned_advance_amount',
            'actual_advance_amount',
            'status',
            'start_date',
            'end_date',
            'notes',
            'is_multi_project',
            'project_ids',
            'is_self_execution',
            'contract_category',
        ];

        $this->replace(array_intersect_key($input, array_flip($allowedFields)));
    }

    public function toDto(): ContractDTO
    {
        $contract = $this->resolveContract();
        $validatedData = $this->validated();
        $isFixedAmount = array_key_exists('is_fixed_amount', $validatedData)
            ? (bool) $validatedData['is_fixed_amount']
            : ($contract->is_fixed_amount ?? true);

        return new ContractDTO(
            project_id: $validatedData['project_id'] ?? $contract->project_id,
            contractor_id: array_key_exists('contractor_id', $validatedData) ? $validatedData['contractor_id'] : $contract->contractor_id,
            parent_contract_id: array_key_exists('parent_contract_id', $validatedData) ? $validatedData['parent_contract_id'] : $contract->parent_contract_id,
            number: $validatedData['number'] ?? $contract->number,
            date: isset($validatedData['date'])
                ? \Carbon\Carbon::parse($validatedData['date'])->format('Y-m-d')
                : ($contract->date ? $contract->date->format('Y-m-d') : now()->format('Y-m-d')),
            subject: array_key_exists('subject', $validatedData) ? $validatedData['subject'] : $contract->subject,
            work_type_category: isset($validatedData['work_type_category'])
                ? ContractWorkTypeCategoryEnum::from($validatedData['work_type_category'])
                : $contract->work_type_category,
            payment_terms: array_key_exists('payment_terms', $validatedData) ? $validatedData['payment_terms'] : $contract->payment_terms,
            base_amount: array_key_exists('base_amount', $validatedData)
                ? ($validatedData['base_amount'] !== null ? (float) $validatedData['base_amount'] : null)
                : $contract->base_amount,
            total_amount: array_key_exists('total_amount', $validatedData)
                ? ($validatedData['total_amount'] !== null ? (float) $validatedData['total_amount'] : null)
                : ($contract->total_amount !== null ? (float) $contract->total_amount : null),
            gp_percentage: array_key_exists('gp_percentage', $validatedData)
                ? ($validatedData['gp_percentage'] !== null ? (float) $validatedData['gp_percentage'] : null)
                : $contract->gp_percentage,
            gp_calculation_type: array_key_exists('gp_calculation_type', $validatedData)
                ? ($validatedData['gp_calculation_type'] ? GpCalculationTypeEnum::from($validatedData['gp_calculation_type']) : null)
                : $contract->gp_calculation_type,
            gp_coefficient: array_key_exists('gp_coefficient', $validatedData)
                ? ($validatedData['gp_coefficient'] !== null ? (float) $validatedData['gp_coefficient'] : null)
                : $contract->gp_coefficient,
            warranty_retention_calculation_type: array_key_exists('warranty_retention_calculation_type', $validatedData)
                ? ($validatedData['warranty_retention_calculation_type'] ? GpCalculationTypeEnum::from($validatedData['warranty_retention_calculation_type']) : null)
                : $contract->warranty_retention_calculation_type,
            warranty_retention_percentage: array_key_exists('warranty_retention_percentage', $validatedData)
                ? ($validatedData['warranty_retention_percentage'] !== null ? (float) $validatedData['warranty_retention_percentage'] : null)
                : $contract->warranty_retention_percentage,
            warranty_retention_coefficient: array_key_exists('warranty_retention_coefficient', $validatedData)
                ? ($validatedData['warranty_retention_coefficient'] !== null ? (float) $validatedData['warranty_retention_coefficient'] : null)
                : $contract->warranty_retention_coefficient,
            subcontract_amount: array_key_exists('subcontract_amount', $validatedData)
                ? ($validatedData['subcontract_amount'] !== null ? (float) $validatedData['subcontract_amount'] : null)
                : $contract->subcontract_amount,
            planned_advance_amount: array_key_exists('planned_advance_amount', $validatedData)
                ? ($validatedData['planned_advance_amount'] !== null ? (float) $validatedData['planned_advance_amount'] : null)
                : $contract->planned_advance_amount,
            actual_advance_amount: array_key_exists('actual_advance_amount', $validatedData)
                ? ($validatedData['actual_advance_amount'] !== null ? (float) $validatedData['actual_advance_amount'] : null)
                : $contract->actual_advance_amount,
            status: isset($validatedData['status']) ? ContractStatusEnum::from($validatedData['status']) : $contract->status,
            start_date: array_key_exists('start_date', $validatedData)
                ? ($validatedData['start_date'] ? \Carbon\Carbon::parse($validatedData['start_date'])->format('Y-m-d') : null)
                : ($contract->start_date ? $contract->start_date->format('Y-m-d') : null),
            end_date: array_key_exists('end_date', $validatedData)
                ? ($validatedData['end_date'] ? \Carbon\Carbon::parse($validatedData['end_date'])->format('Y-m-d') : null)
                : ($contract->end_date ? $contract->end_date->format('Y-m-d') : null),
            notes: array_key_exists('notes', $validatedData) ? $validatedData['notes'] : $contract->notes,
            advance_payments: null,
            is_fixed_amount: $isFixedAmount,
            is_multi_project: array_key_exists('is_multi_project', $validatedData)
                ? (bool) $validatedData['is_multi_project']
                : (bool) ($contract->is_multi_project ?? false),
            project_ids: array_key_exists('project_ids', $validatedData) ? $validatedData['project_ids'] : null,
            is_self_execution: array_key_exists('is_self_execution', $validatedData)
                ? (bool) $validatedData['is_self_execution']
                : (bool) $contract->is_self_execution,
            supplier_id: array_key_exists('supplier_id', $validatedData) ? $validatedData['supplier_id'] : $contract->supplier_id,
            contract_category: array_key_exists('contract_category', $validatedData)
                ? $validatedData['contract_category']
                : $contract->contract_category,
            contract_side_type: array_key_exists('contract_side_type', $validatedData)
                ? ($validatedData['contract_side_type'] ? ContractSideTypeEnum::from($validatedData['contract_side_type']) : null)
                : $contract->contract_side_type
        );
    }

    private function resolveContract(): Contract
    {
        return Contract::findOrFail($this->route('contract'));
    }
}
