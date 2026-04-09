<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Services\Contractor\SelfExecutionService;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectCustomerResolverService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractSideMutationService
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractPaymentRepositoryInterface $paymentRepository,
        private readonly ContractAccessService $contractAccessService,
        private readonly LoggingService $logging,
        private readonly ContractorSharingInterface $contractorSharing,
        private readonly ProjectCustomerResolverService $projectCustomerResolverService,
        private readonly SelfExecutionService $selfExecutionService,
        private readonly ContractStateEventService $stateEventService,
    ) {
    }

    public function create(
        int $organizationId,
        ContractDTO $contractDTO,
        ?ProjectContext $projectContext = null
    ): Contract {
        if ($projectContext && !$projectContext->roleConfig->canManageContracts) {
            throw new Exception(
                'Ваша роль "' . $projectContext->roleConfig->displayLabel . '" не позволяет создавать договоры в этом проекте'
            );
        }

        $contractDTO = $this->resolveContractParties($organizationId, $contractDTO, $projectContext);
        $targetOrganizationId = $this->resolveOwnerOrganizationId($organizationId, $contractDTO);

        $this->assertFixedAmountContractIsValid($contractDTO);
        $this->assertContractorCanBeUsed($contractDTO, $targetOrganizationId);

        $this->logging->business('contract.creation.started', [
            'organization_id' => $targetOrganizationId,
            'contractor_id' => $contractDTO->contractor_id,
            'supplier_id' => $contractDTO->supplier_id,
            'contract_side_type' => $contractDTO->contract_side_type?->value,
            'contract_number' => $contractDTO->number,
            'project_id' => $contractDTO->project_id,
            'user_id' => Auth::id(),
            'has_project_context' => $projectContext !== null,
            'is_self_execution' => $contractDTO->is_self_execution,
        ]);

        $contractData = $contractDTO->toArray();
        $contractData['organization_id'] = $targetOrganizationId;

        if (!$contractDTO->is_fixed_amount && (!isset($contractData['total_amount']) || $contractData['total_amount'] === null)) {
            $contractData['total_amount'] = 0;
        }

        $projectIds = $contractData['project_ids'] ?? null;
        unset($contractData['project_ids']);

        try {
            DB::beginTransaction();

            $advancePayments = $contractDTO->advance_payments;
            unset($contractData['advance_payments']);

            $contract = $this->contractRepository->create($contractData);

            $this->syncProjects($contract, $contractDTO, $projectIds, $targetOrganizationId);

            if (is_array($advancePayments)) {
                foreach ($advancePayments as $advance) {
                    $this->paymentRepository->create([
                        'contract_id' => $contract->id,
                        'amount' => $advance['amount'],
                        'payment_date' => $advance['payment_date'] ?? null,
                        'payment_type' => 'advance',
                        'description' => $advance['description'] ?? null,
                    ]);
                }
            }

            DB::commit();

            try {
                $this->stateEventService->createContractCreatedEvent($contract);
            } catch (Exception $exception) {
                Log::warning('Failed to create contract state event', [
                    'contract_id' => $contract->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->logging->business('contract.created', [
                'organization_id' => $targetOrganizationId,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'contractor_id' => $contract->contractor_id,
                'supplier_id' => $contract->supplier_id,
                'contract_side_type' => $contract->contract_side_type?->value,
                'user_id' => Auth::id(),
            ]);

            $this->logging->audit('contract.created', [
                'organization_id' => $targetOrganizationId,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'transaction_type' => 'contract_created',
                'performed_by' => Auth::id() ?? 'system',
                'contract_amount' => $contract->total_amount,
            ]);

            return $contract;
        } catch (Exception $exception) {
            DB::rollBack();

            $this->logging->business('contract.creation.failed', [
                'organization_id' => $targetOrganizationId,
                'contract_number' => $contractDTO->number ?? null,
                'error_message' => $exception->getMessage(),
                'user_id' => Auth::id(),
            ], 'error');

            throw $exception;
        }
    }

    public function update(int $contractId, int $organizationId, ContractDTO $contractDTO): Contract
    {
        $contract = $this->contractAccessService->findAccessible($contractId, $organizationId);

        if (!$contract) {
            throw new Exception('Contract not found.');
        }

        $sideChanged = $contractDTO->contract_side_type !== null
            && $contract->contract_side_type !== $contractDTO->contract_side_type;

        if ($sideChanged && ($contract->performanceActs()->exists() || $contract->payments()->exists())) {
            throw new Exception('Нельзя менять стороны договора после появления актов или платежей.');
        }

        $contractDTO = $this->resolveContractParties($organizationId, $contractDTO, null);
        $targetOrganizationId = $this->resolveOwnerOrganizationId($organizationId, $contractDTO);

        $this->assertFixedAmountContractIsValid($contractDTO);
        $this->assertContractorCanBeUsed($contractDTO, $targetOrganizationId);

        $this->logging->business('contract.update.started', [
            'organization_id' => $targetOrganizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'contract_side_type' => $contractDTO->contract_side_type?->value,
            'user_id' => Auth::id(),
        ]);

        $updateData = $contractDTO->toArray();
        $updateData['organization_id'] = $targetOrganizationId;
        $projectIds = $updateData['project_ids'] ?? null;
        unset($updateData['project_ids']);

        if (!$contractDTO->is_fixed_amount && (!isset($updateData['total_amount']) || $updateData['total_amount'] === null)) {
            $updateData['total_amount'] = 0;
        }

        try {
            DB::beginTransaction();

            $contract->update($updateData);
            $this->syncProjects($contract, $contractDTO, $projectIds, $targetOrganizationId);

            DB::commit();

            $contract->refresh();

            $this->logging->business('contract.updated', [
                'organization_id' => $targetOrganizationId,
                'contract_id' => $contractId,
                'contract_number' => $contract->number,
                'contract_side_type' => $contract->contract_side_type?->value,
                'user_id' => Auth::id(),
            ]);

            $this->logging->audit('contract.updated', [
                'organization_id' => $targetOrganizationId,
                'contract_id' => $contractId,
                'contract_number' => $contract->number,
                'transaction_type' => 'contract_updated',
                'performed_by' => Auth::id() ?? 'system',
                'contract_side_type' => $contract->contract_side_type?->value,
            ]);

            return $contract;
        } catch (Exception $exception) {
            DB::rollBack();

            $this->logging->business('contract.update.failed', [
                'organization_id' => $targetOrganizationId,
                'contract_id' => $contractId,
                'contract_number' => $contract->number,
                'error_message' => $exception->getMessage(),
                'user_id' => Auth::id(),
            ], 'error');

            throw $exception;
        }
    }

    public function resolveReview(int $contractId, int $organizationId, ContractSideTypeEnum $sideType): Contract
    {
        $contract = Contract::query()
            ->with(['project.organizations', 'contractor.sourceOrganization', 'supplier'])
            ->whereKey($contractId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$contract) {
            throw new Exception('Contract not found.');
        }

        $dto = new ContractDTO(
            project_id: $contract->project_id,
            contractor_id: $contract->contractor_id,
            parent_contract_id: $contract->parent_contract_id,
            number: $contract->number,
            date: $contract->date?->format('Y-m-d') ?? now()->toDateString(),
            subject: $contract->subject,
            work_type_category: $contract->work_type_category,
            payment_terms: $contract->payment_terms,
            base_amount: $contract->base_amount,
            total_amount: $contract->total_amount,
            gp_percentage: $contract->gp_percentage !== null ? (float) $contract->gp_percentage : null,
            gp_calculation_type: $contract->gp_calculation_type,
            gp_coefficient: $contract->gp_coefficient !== null ? (float) $contract->gp_coefficient : null,
            warranty_retention_calculation_type: $contract->warranty_retention_calculation_type,
            warranty_retention_percentage: $contract->warranty_retention_percentage !== null ? (float) $contract->warranty_retention_percentage : null,
            warranty_retention_coefficient: $contract->warranty_retention_coefficient !== null ? (float) $contract->warranty_retention_coefficient : null,
            subcontract_amount: $contract->subcontract_amount !== null ? (float) $contract->subcontract_amount : null,
            planned_advance_amount: $contract->planned_advance_amount !== null ? (float) $contract->planned_advance_amount : null,
            actual_advance_amount: $contract->actual_advance_amount !== null ? (float) $contract->actual_advance_amount : null,
            status: $contract->status,
            start_date: $contract->start_date?->format('Y-m-d'),
            end_date: $contract->end_date?->format('Y-m-d'),
            notes: $contract->notes,
            advance_payments: null,
            is_fixed_amount: (bool) $contract->is_fixed_amount,
            is_multi_project: (bool) $contract->is_multi_project,
            project_ids: $contract->is_multi_project ? $contract->projects()->pluck('projects.id')->all() : null,
            is_self_execution: (bool) $contract->is_self_execution,
            supplier_id: $contract->supplier_id,
            contract_category: $contract->contract_category,
            contract_side_type: $sideType,
        );

        $resolvedDto = $this->resolveContractParties($organizationId, $dto, null);
        $targetOrganizationId = $this->resolveOwnerOrganizationId($organizationId, $resolvedDto);

        $this->assertContractorCanBeUsed($resolvedDto, $targetOrganizationId);

        $payload = [
            'organization_id' => $targetOrganizationId,
            'contractor_id' => $resolvedDto->contractor_id,
            'supplier_id' => $resolvedDto->supplier_id,
            'contract_side_type' => $resolvedDto->contract_side_type?->value,
            'requires_contract_side_review' => false,
            'contract_side_review_reason' => null,
        ];

        $previousSide = $contract->contract_side_type?->value;

        $contract->update($payload);
        $contract->refresh();

        $this->logging->business('contract.side_review.resolved', [
            'organization_id' => $targetOrganizationId,
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'old_contract_side_type' => $previousSide,
            'new_contract_side_type' => $contract->contract_side_type?->value,
            'user_id' => Auth::id(),
        ]);

        $this->logging->audit('contract.side_review.resolved', [
            'organization_id' => $targetOrganizationId,
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'transaction_type' => 'contract_side_review_resolved',
            'performed_by' => Auth::id() ?? 'system',
            'old_contract_side_type' => $previousSide,
            'new_contract_side_type' => $contract->contract_side_type?->value,
        ]);

        return $contract;
    }

    private function syncProjects(Contract $contract, ContractDTO $contractDTO, ?array $projectIds, int $targetOrganizationId): void
    {
        $projectIdsToSync = $contractDTO->is_multi_project
            ? array_values(array_filter($projectIds ?? [], static fn (?int $projectId): bool => $projectId !== null))
            : ($contractDTO->project_id ? [$contractDTO->project_id] : []);

        if ($projectIdsToSync === []) {
            return;
        }

        $this->assertProjectsAvailableForContract($projectIdsToSync, $targetOrganizationId, $contractDTO->contract_side_type);
        $contract->syncProjects($projectIdsToSync);
    }

    private function assertFixedAmountContractIsValid(ContractDTO $contractDTO): void
    {
        if ($contractDTO->is_fixed_amount && $contractDTO->base_amount === null) {
            throw new Exception('Для договора с фиксированной суммой необходимо указать базовую сумму.');
        }
    }

    private function assertContractorCanBeUsed(ContractDTO $contractDTO, int $targetOrganizationId): void
    {
        if ($contractDTO->contractor_id === null) {
            return;
        }

        if ($this->contractorSharing->canUseContractor($contractDTO->contractor_id, $targetOrganizationId)) {
            return;
        }

        $contractor = Contractor::find($contractDTO->contractor_id);
        $contractorName = $contractor ? $contractor->name : "ID {$contractDTO->contractor_id}";

        throw new Exception("Подрядчик \"{$contractorName}\" недоступен для организации-владельца договора.");
    }

    private function resolveContractParties(
        int $organizationId,
        ContractDTO $contractDTO,
        ?ProjectContext $projectContext
    ): ContractDTO {
        $sideType = $contractDTO->contract_side_type;

        if (!$sideType instanceof ContractSideTypeEnum) {
            throw new Exception('Не указан тип договора.');
        }

        $this->assertSideAllowedForProjectContext($sideType, $projectContext);

        $contractorId = $contractDTO->contractor_id;
        $supplierId = $contractDTO->supplier_id;

        if ($sideType->requiresSupplier()) {
            if (!$supplierId) {
                throw new Exception('Для договора с поставщиком нужно выбрать поставщика.');
            }

            $contractorId = null;
            $supplierId = (int) $supplierId;
        }

        if ($sideType === ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR) {
            $supplierId = null;

            if ($contractDTO->is_self_execution) {
                $contractorId = $this->resolveSelfExecutionContractorId($organizationId);
            }

            if (!$contractorId) {
                throw new Exception('Для договора с подрядчиком нужно выбрать подрядчика.');
            }
        }

        if ($sideType === ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR) {
            $supplierId = null;

            if (!$contractorId) {
                throw new Exception('Для договора с субподрядчиком нужно выбрать субподрядчика.');
            }
        }

        if ($sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR) {
            $supplierId = null;
            $customerOrganizationId = $this->resolveProjectCustomerOrganizationId($contractDTO);

            if ($projectContext && in_array($projectContext->roleConfig->role->value, ['general_contractor', 'contractor'], true)) {
                $contractor = Contractor::firstOrCreate(
                    [
                        'organization_id' => $customerOrganizationId,
                        'source_organization_id' => $organizationId,
                    ],
                    [
                        'name' => $projectContext->organizationName ?? 'Исполнитель по договору',
                        'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
                        'connected_at' => now(),
                    ]
                );

                $contractorId = $contractor->id;
            }

            if (!$contractorId) {
                throw new Exception('Для договора между заказчиком и генподрядчиком нужно выбрать исполнителя.');
            }
        }

        return new ContractDTO(
            project_id: $contractDTO->project_id,
            contractor_id: $contractorId,
            parent_contract_id: $contractDTO->parent_contract_id,
            number: $contractDTO->number,
            date: $contractDTO->date,
            subject: $contractDTO->subject,
            work_type_category: $contractDTO->work_type_category,
            payment_terms: $contractDTO->payment_terms,
            base_amount: $contractDTO->base_amount,
            total_amount: $contractDTO->total_amount,
            gp_percentage: $contractDTO->gp_percentage,
            gp_calculation_type: $contractDTO->gp_calculation_type,
            gp_coefficient: $contractDTO->gp_coefficient,
            warranty_retention_calculation_type: $contractDTO->warranty_retention_calculation_type,
            warranty_retention_percentage: $contractDTO->warranty_retention_percentage,
            warranty_retention_coefficient: $contractDTO->warranty_retention_coefficient,
            subcontract_amount: $contractDTO->subcontract_amount,
            planned_advance_amount: $contractDTO->planned_advance_amount,
            actual_advance_amount: $contractDTO->actual_advance_amount,
            status: $contractDTO->status,
            start_date: $contractDTO->start_date,
            end_date: $contractDTO->end_date,
            notes: $contractDTO->notes,
            advance_payments: $contractDTO->advance_payments,
            is_fixed_amount: $contractDTO->is_fixed_amount,
            is_multi_project: $contractDTO->is_multi_project,
            project_ids: $contractDTO->project_ids,
            is_self_execution: $sideType->allowsSelfExecution() ? $contractDTO->is_self_execution : false,
            supplier_id: $supplierId,
            contract_category: $contractDTO->contract_category,
            contract_side_type: $sideType,
        );
    }

    private function resolveOwnerOrganizationId(int $organizationId, ContractDTO $contractDTO): int
    {
        return $contractDTO->contract_side_type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            ? $this->resolveProjectCustomerOrganizationId($contractDTO)
            : $organizationId;
    }

    private function resolveProjectCustomerOrganizationId(ContractDTO $contractDTO): int
    {
        $project = $this->resolvePrimaryProject($contractDTO);

        if (!$project) {
            throw new Exception('Для этого типа договора нужен проект с определенным заказчиком.');
        }

        $project->loadMissing('organizations');
        $resolvedCustomerId = $this->projectCustomerResolverService->resolveOrganizationId($project);

        if ($resolvedCustomerId === null) {
            throw new Exception('Не удалось определить заказчика проекта для выбранного типа договора.');
        }

        return (int) $resolvedCustomerId;
    }

    private function resolvePrimaryProject(ContractDTO $contractDTO): ?Project
    {
        $projectId = $contractDTO->project_id;

        if ($projectId === null && is_array($contractDTO->project_ids) && !empty($contractDTO->project_ids)) {
            $projectId = (int) $contractDTO->project_ids[0];
        }

        return $projectId ? Project::find($projectId) : null;
    }

    private function assertSideAllowedForProjectContext(
        ContractSideTypeEnum $sideType,
        ?ProjectContext $projectContext
    ): void {
        if ($projectContext === null) {
            return;
        }

        $role = $projectContext->roleConfig->role->value;

        $allowedRoles = match ($sideType) {
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR => ['owner', 'customer', 'general_contractor', 'contractor'],
            ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
            ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER => ['owner', 'general_contractor'],
            ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR,
            ContractSideTypeEnum::CONTRACTOR_TO_SUPPLIER => ['owner', 'contractor'],
            ContractSideTypeEnum::SUBCONTRACTOR_TO_SUPPLIER => ['owner', 'subcontractor'],
        };

        if (!in_array($role, $allowedRoles, true)) {
            throw new Exception('Текущая роль в проекте не позволяет создать договор с выбранными сторонами.');
        }
    }

    private function resolveSelfExecutionContractorId(int $organizationId): int
    {
        if (!$this->selfExecutionService->canUseSelfExecution($organizationId)) {
            throw new Exception('Организация не может использовать собственные силы для договора.');
        }

        return $this->selfExecutionService->getOrCreateForOrganization($organizationId)->id;
    }

    private function assertProjectsAvailableForContract(
        array $projectIds,
        int $targetOrganizationId,
        ?ContractSideTypeEnum $sideType
    ): void {
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->with('organizations')
            ->get();

        if ($projects->count() !== count($projectIds)) {
            throw new Exception('Некоторые проекты не найдены.');
        }

        foreach ($projects as $project) {
            if ($sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR) {
                $resolvedCustomerId = $this->projectCustomerResolverService->resolveOrganizationId($project);

                if ((int) $resolvedCustomerId !== $targetOrganizationId) {
                    throw new Exception('Выбранный проект не связан с заказчиком, который должен быть стороной договора.');
                }

                continue;
            }

            if ((int) $project->organization_id !== $targetOrganizationId) {
                throw new Exception('Некоторые проекты не принадлежат организации-владельцу договора.');
            }
        }
    }
}
