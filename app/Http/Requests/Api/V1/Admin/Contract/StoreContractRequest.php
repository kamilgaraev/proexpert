<?php

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Rules\ParentContractValid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $routeProjectId = $this->routeProjectId();

        if ($user === null) {
            return false;
        }

        $projectIds = $routeProjectId !== null ? [$routeProjectId] : [];
        if ($routeProjectId !== null && $this->boolean('is_multi_project')) {
            $projectIds = array_merge($projectIds, $this->numericProjectIds($this->input('project_ids', [])));
        }

        $authorization = app(AuthorizationService::class);
        $baseContext = ['organization_id' => $this->currentOrganizationId()];

        if ($projectIds === []) {
            return $authorization->can($user, 'contracts.create', $baseContext);
        }

        foreach (array_unique($projectIds) as $projectId) {
            if (! $authorization->can($user, 'contracts.create', $baseContext + ['project_id' => $projectId])) {
                return false;
            }
        }

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

                    if (! $accessController->hasModuleAccess($organizationId, 'procurement')) {
                        $validator->errors()->add(
                            'supplier_id',
                            'Модуль "Управление закупками" не активирован. Активируйте модуль для создания договоров поставки.'
                        );
                    }

                    if (! $accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
                        $validator->errors()->add(
                            'supplier_id',
                            'Модуль "Базовое управление складом" не активирован. Он необходим для работы с договорами поставки.'
                        );
                    }
                }
            }

            if (! $sideType) {
                return;
            }

            if ($sideType->requiresSupplier()) {
                if (! $supplierId) {
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
                if (! $contractorId && ! $isSelfExecution) {
                    $validator->errors()->add('contractor_id', 'Для этого типа договора нужно выбрать подрядчика или включить собственные силы.');
                }

                if ($supplierId) {
                    $validator->errors()->add('supplier_id', 'Для договора с подрядчиком поставщик не заполняется.');
                }
            }

            if ($sideType === ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR) {
                if (! $contractorId) {
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
        $organizationId = $this->currentOrganizationId();
        $routeProjectId = $this->routeProjectId();
        $isMultiProject = $this->boolean('is_multi_project');
        $projectIdRules = [
            'nullable',
            'integer',
            Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            'required_without:project_ids',
        ];
        $projectIdsRules = ['nullable', 'required_if:is_multi_project,true,1', 'array', 'min:1'];
        $projectIdsItemRules = [
            'integer',
            Rule::exists('projects', 'id')->where('organization_id', $organizationId),
        ];

        if ($routeProjectId !== null) {
            $projectIdRules = [
                $isMultiProject ? 'nullable' : 'required',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
                Rule::in([$routeProjectId]),
            ];
            $projectIdsItemRules[] = 'distinct';
            if ($isMultiProject) {
                $projectIdsRules[] = static function (string $attribute, mixed $value, \Closure $fail) use ($routeProjectId): void {
                    if (is_array($value) && ! in_array($routeProjectId, array_map('intval', $value), true)) {
                        $fail(trans_message('contracts.route_project_required'));
                    }
                };
            }
        }

        return [
            'project_id' => $projectIdRules,
            'contract_side_type' => ['required', new Enum(ContractSideTypeEnum::class)],
            'contractor_id' => [
                'nullable',
                'integer',
                'exists:contractors,id',
                $this->availableContractorRule($organizationId),
            ],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('organization_id', $organizationId),
            ],
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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'is_multi_project' => ['nullable', 'boolean'],
            'project_ids' => $projectIdsRules,
            'project_ids.*' => $projectIdsItemRules,
            'organization_id_for_creation' => ['sometimes', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'document_title' => ['nullable', 'string', 'max:512'],
            'document_profile_code' => ['nullable', 'string', 'max:191'],
            'document_metadata' => ['nullable', 'array'],
            'document_confidentiality_level' => ['nullable', 'in:internal,restricted,secret'],
        ];
    }

    protected function currentOrganizationId(): int
    {
        return (int) (
            $this->attributes->get('current_organization_id')
            ?? $this->user()?->current_organization_id
            ?? $this->input('organization_id_for_creation')
        );
    }

    protected function prepareForValidation(): void
    {
        $routeProjectId = $this->routeProjectId();

        if ($routeProjectId !== null && ! $this->has('project_id')) {
            $this->merge(['project_id' => $this->boolean('is_multi_project') ? null : $routeProjectId]);
        }
    }

    protected function routeProjectId(): ?int
    {
        $project = $this->route('project');

        if ($project instanceof Model) {
            return (int) $project->getKey();
        }

        return $project !== null ? (int) $project : null;
    }

    /** @return array<int, int> */
    private function numericProjectIds(mixed $projectIds): array
    {
        if (! is_array($projectIds)) {
            return [];
        }

        return array_map('intval', array_filter($projectIds, 'is_numeric'));
    }

    private function availableContractorRule(int $organizationId): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($organizationId): void {
            if (! $value) {
                return;
            }

            if (! app(ContractorSharingInterface::class)->canUseContractor((int) $value, $organizationId)) {
                $fail(trans_message('contract.contractor_not_available'));
            }
        };
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
            gp_calculation_type: $this->validated('gp_calculation_type')
                ? GpCalculationTypeEnum::from($this->validated('gp_calculation_type'))
                : GpCalculationTypeEnum::PERCENTAGE,
            gp_coefficient: $this->validated('gp_coefficient') !== null ? (float) $this->validated('gp_coefficient') : null,
            warranty_retention_calculation_type: $this->validated('warranty_retention_calculation_type') ? GpCalculationTypeEnum::from($this->validated('warranty_retention_calculation_type')) : null,
            warranty_retention_percentage: $this->validated('warranty_retention_percentage') !== null ? (float) $this->validated('warranty_retention_percentage') : null,
            warranty_retention_coefficient: $this->validated('warranty_retention_coefficient') !== null ? (float) $this->validated('warranty_retention_coefficient') : null,
            subcontract_amount: $this->validated('subcontract_amount') !== null ? (float) $this->validated('subcontract_amount') : null,
            planned_advance_amount: $this->validated('planned_advance_amount') !== null ? (float) $this->validated('planned_advance_amount') : null,
            actual_advance_amount: $this->validated('actual_advance_amount') !== null ? (float) $this->validated('actual_advance_amount') : null,
            status: ContractStatusEnum::DRAFT,
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
