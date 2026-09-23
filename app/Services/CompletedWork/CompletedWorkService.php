<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\ProjectOrganizationRole;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\ContractException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectContextService;
use App\Services\RateCoefficient\RateCoefficientService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompletedWorkService
{
    protected CompletedWorkRepositoryInterface $completedWorkRepository;

    protected RateCoefficientService $rateCoefficientService;

    protected LoggingService $logging;

    protected ProjectContextService $projectContextService;

    protected ContractAuditedMutationService $contractMutations;

    protected CompletedWorkScopeResolver $scopeResolver;

    public function __construct(
        CompletedWorkRepositoryInterface $completedWorkRepository,
        RateCoefficientService $rateCoefficientService,
        LoggingService $logging,
        ProjectContextService $projectContextService,
        ContractAuditedMutationService $contractMutations,
        CompletedWorkScopeResolver $scopeResolver,
        private readonly CompletedWorkMutationGuard $mutationGuard,
        private readonly CompletedWorkFactReadiness $factReadiness,
    ) {
        $this->completedWorkRepository = $completedWorkRepository;
        $this->rateCoefficientService = $rateCoefficientService;
        $this->logging = $logging;
        $this->projectContextService = $projectContextService;
        $this->contractMutations = $contractMutations;
        $this->scopeResolver = $scopeResolver;
    }

    public function getAll(array $filters = [], int $perPage = 15, string $sortBy = 'completion_date', string $sortDirection = 'desc', array $relations = []): LengthAwarePaginator
    {
        // Добавляем сортировку по умолчанию для выполненных работ
        return $this->completedWorkRepository->getAllPaginated($filters, $perPage, $sortBy, $sortDirection, $relations);
    }

    public function getById(int $id, int $organizationId): CompletedWork
    {
        $completedWork = $this->completedWorkRepository->findById($id, $organizationId);
        if (! $completedWork) {
            throw new BusinessLogicException('Запись о выполненной работе не найдена.', 404);
        }

        return $completedWork;
    }

    public function create(CompletedWorkDTO $dto, ?ProjectContext $projectContext = null, ?User $actor = null): CompletedWork
    {
        $actor ??= request()->user();
        $this->scopeResolver->assertCreate($dto, $actor, $projectContext);
        $this->assertCreateSourcePolicy($dto);

        // Project-Based RBAC: валидация прав и auto-fill contractor_org_id
        if ($projectContext) {
            // Проверка: может ли роль создавать работы
            if (! $projectContext->roleConfig->canManageWorks) {
                throw new BusinessLogicException(
                    'Ваша роль "'.$projectContext->roleConfig->displayLabel.
                    '" не позволяет создавать работы в этом проекте',
                    403
                );
            }

            // Auto-fill contractor_id для contractor/subcontractor ролей
            $contractorId = $dto->contractor_id;

            if (in_array($projectContext->roleConfig->role, [ProjectOrganizationRole::CONTRACTOR, ProjectOrganizationRole::SUBCONTRACTOR], true)) {
                $resolvedContractorId = Contractor::query()
                    ->where('organization_id', $dto->organization_id)
                    ->where('source_organization_id', $projectContext->organizationId)
                    ->value('id');

                if (! $resolvedContractorId) {
                    throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
                }

                if ($contractorId && (int) $contractorId !== (int) $resolvedContractorId) {
                    throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
                }

                $contractorId = (int) $resolvedContractorId;

                // Создаем новый DTO с исправленным contractor_id
                $dto = new CompletedWorkDTO(
                    id: $dto->id,
                    organization_id: $dto->organization_id,
                    project_id: $dto->project_id,
                    schedule_task_id: $dto->schedule_task_id,
                    estimate_item_id: $dto->estimate_item_id,
                    journal_entry_id: $dto->journal_entry_id,
                    work_origin_type: $dto->work_origin_type,
                    planning_status: $dto->planning_status,
                    contract_id: $dto->contract_id,
                    contractor_id: $contractorId,
                    work_type_id: $dto->work_type_id,
                    user_id: $dto->user_id,
                    quantity: $dto->quantity,
                    completed_quantity: $dto->completed_quantity,
                    price: $dto->price,
                    total_amount: $dto->total_amount,
                    completion_date: $dto->completion_date,
                    notes: $dto->notes,
                    status: $dto->status,
                    additional_info: $dto->additional_info,
                    materials: $dto->materials,
                    description: $dto->description
                );

                $this->logging->technical('contractor_id auto-filled', [
                    'organization_id' => $dto->organization_id,
                    'contractor_id' => $contractorId,
                    'role' => $projectContext->roleConfig->role->value,
                ]);
            }

            // УДАЛЕНА НЕВЕРНАЯ ВАЛИДАЦИЯ:
            // Подрядчик НЕ обязан быть участником проекта!
            // Подрядчик может быть внешним контрагентом (не зарегистрирован как организация)
            // Проверка доступности подрядчика происходит через ContractorSharing в других местах
        }

        // BUSINESS: Начало создания выполненной работы
        $this->logging->business('completed_work.creation.started', [
            'project_id' => $dto->project_id,
            'contract_id' => $dto->contract_id,
            'work_type_id' => $dto->work_type_id,
            'organization_id' => $dto->organization_id,
            'contractor_id' => $dto->contractor_id ?? null,
            'quantity' => $dto->quantity,
            'price' => $dto->price,
            'total_amount' => $dto->total_amount,
            'status' => $dto->status,
            'has_materials' => ! empty($dto->materials),
            'materials_count' => count($dto->materials ?? []),
            'has_project_context' => $projectContext !== null,
        ]);

        return DB::transaction(function () use ($dto) {
            $data = $this->prepareFinancialData($dto);

            if ($dto->contract_id) {
                $this->validateContract(
                    $dto->contract_id,
                    $data['total_amount'],
                    $dto->organization_id,
                    $dto->project_id,
                    $dto->contractor_id
                );
            }

            $createdModel = $this->completedWorkRepository->create($data);

            if (! $createdModel) {
                // TECHNICAL: Ошибка создания записи в БД
                $this->logging->technical('completed_work.creation.failed.database', [
                    'project_id' => $dto->project_id,
                    'contract_id' => $dto->contract_id,
                    'organization_id' => $dto->organization_id,
                ], 'error');

                throw new BusinessLogicException('Не удалось создать запись о выполненной работе.', 500);
            }

            if ($dto->status === CompletedWork::STATUS_IN_REVIEW) {
                $this->factReadiness->assertReady($createdModel);
            }

            if ($dto->materials) {
                $this->syncMaterials($createdModel, $dto->materials);
            }

            // Обновление статуса контракта после создания работы
            if ($dto->contract_id && $dto->status === 'confirmed') {
                $this->updateContractStatus($dto->contract_id);
            }

            // BUSINESS: Выполненная работа успешно создана
            $this->logging->business('completed_work.created', [
                'work_id' => $createdModel->id,
                'project_id' => $createdModel->project_id,
                'contract_id' => $createdModel->contract_id,
                'work_type_id' => $createdModel->work_type_id,
                'organization_id' => $createdModel->organization_id,
                'final_amount' => $createdModel->total_amount,
                'quantity' => $createdModel->quantity,
                'status' => $createdModel->status,
                'has_materials' => $createdModel->materials()->count() > 0,
            ]);

            // AUDIT: Создание финансово значимой записи
            $this->logging->audit('completed_work.created', [
                'work_id' => $createdModel->id,
                'project_id' => $createdModel->project_id,
                'contract_id' => $createdModel->contract_id,
                'organization_id' => $createdModel->organization_id,
                'amount' => $createdModel->total_amount,
                'completion_date' => $createdModel->completion_date,
                'status' => $createdModel->status,
                'performed_by' => request()->user()?->id,
            ]);

            return $createdModel->fresh(['materials.measurementUnit']);
        });
    }

    public function createMany(array $dtos, User $actor, ?ProjectContext $projectContext = null): array
    {
        return DB::transaction(function () use ($dtos, $actor, $projectContext): array {
            $this->scopeResolver->assertBulk($dtos, $actor, $projectContext);
            foreach ($dtos as $dto) {
                $this->assertCreateSourcePolicy($dto);
            }

            return array_map(fn (CompletedWorkDTO $dto): CompletedWork => $this->create($dto, $projectContext, $actor), $dtos);
        });
    }

    public function update(int $id, CompletedWorkDTO $dto, ?User $actor = null, ?ProjectContext $projectContext = null): CompletedWork
    {
        $actor ??= request()->user();
        return DB::transaction(function () use ($id, $dto, $actor, $projectContext) {
            $existingWork = $this->lockedWork($id, $dto->organization_id);

            $this->scopeResolver->assertUpdate($existingWork, $dto, $actor, $projectContext);
            $this->assertOrdinaryMutationAllowed($existingWork);

            $this->assertUpdateSourcePolicy($existingWork, $dto);

            $data = $this->prepareUpdatedFinancialData($existingWork, $dto);
            $newContractId = $dto->contract_id;
            $contractChanged = (int) ($newContractId ?? 0) !== (int) ($existingWork->contract_id ?? 0);
            $contractorChanged = (int) ($dto->contractor_id ?? 0) !== (int) ($existingWork->contractor_id ?? 0);
            $projectChanged = (int) $dto->project_id !== (int) $existingWork->project_id;
            $statusChanged = $dto->status !== $existingWork->status;
            $finalAmount = (float) ($data['total_amount'] ?? 0);
            $existingAmount = (float) ($existingWork->total_amount ?? 0);

            if ($newContractId && ($contractChanged || $contractorChanged || $projectChanged || $statusChanged || $finalAmount !== $existingAmount)) {
                $amountToValidate = $contractChanged
                    ? $finalAmount
                    : max(0.0, $finalAmount - $existingAmount);

                $this->validateContract(
                    $newContractId,
                    $amountToValidate,
                    $dto->organization_id,
                    $dto->project_id,
                    $dto->contractor_id
                );
            }


            $success = $this->completedWorkRepository->update($id, $data);
            if (! $success) {
                throw new BusinessLogicException('Не удалось обновить запись о выполненной работе.', 500);
            }

            $updatedWork = $existingWork->refresh();

            if ($dto->status === CompletedWork::STATUS_IN_REVIEW) {
                $this->factReadiness->assertReady($updatedWork);
            }

            if ($dto->materials !== null) {
                $this->syncMaterials($updatedWork, $dto->materials);
            }

            // Обновление статуса контракта после изменения работы
            if ($dto->contract_id && $dto->status === 'confirmed') {
                $this->updateContractStatus($dto->contract_id);
            }

            return $updatedWork->fresh(['materials.measurementUnit']);
        });
    }

    public function delete(int $id, int $organizationId, ?User $actor = null, ?ProjectContext $projectContext = null): bool
    {
        $actor ??= request()->user();
        return DB::transaction(function () use ($id, $organizationId, $actor, $projectContext): bool {
            $work = $this->lockedWork($id, $organizationId);
            $this->scopeResolver->assertDelete($work, $actor, $projectContext);
            $this->assertOrdinaryMutationAllowed($work);

            if (! $this->completedWorkRepository->delete($id)) {
                throw new BusinessLogicException('Не удалось удалить запись о выполненной работе.', 500);
            }

            return true;
        });
    }

    private function lockedWork(int $id, int $organizationId): CompletedWork
    {
        return CompletedWork::query()->where('organization_id', $organizationId)->lockForUpdate()->find($id)
            ?? throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
    }

    private function assertOrdinaryMutationAllowed(CompletedWork $work): void
    {
        $this->mutationGuard->assertMutable($work);
    }

    private function prepareUpdatedFinancialData(CompletedWork $work, CompletedWorkDTO $dto): array
    {
        $samePrice = ! $this->nullableFloatChanged($dto->price, $work->price);
        $sameTotal = $dto->total_amount === null
            || ! $this->nullableFloatChanged($dto->total_amount, $work->total_amount)
            || ($samePrice && abs($dto->total_amount - round((float) $dto->price * $dto->quantity, 2)) < 0.0000001);
        $sameQuantity = abs($dto->quantity - (float) $work->quantity) < 0.0000001;
        $calculation = $work->additional_info['financial_calculation'] ?? null;

        if ($samePrice && $sameTotal && $sameQuantity) {
            $data = $this->prepareFinancialData($dto, false);
            $data['price'] = $work->price;
            $data['total_amount'] = $work->total_amount;
            if ($calculation !== null) {
                $data['additional_info']['financial_calculation'] = $calculation;
            }

            return $data;
        }

        if ($samePrice && $sameTotal) {
            $basePrice = $calculation['base_unit_price'] ?? null;
            if ($basePrice === null && isset($calculation['base_total_amount']) && (float) $work->quantity > 0) {
                $basePrice = (float) $calculation['base_total_amount'] / (float) $work->quantity;
            }

            return $this->prepareFinancialData($dto, $basePrice !== null, [
                'price' => $basePrice ?? $dto->price,
                'total_amount' => null,
            ]);
        }

        return $this->prepareFinancialData($dto);
    }

    private function prepareFinancialData(CompletedWorkDTO $dto, bool $applyCoefficients = true, array $financialOverrides = []): array
    {
        if (! is_finite($dto->quantity) || $dto->quantity < 0 || $dto->quantity >= 100000000000000
            || round($dto->quantity, 4) !== $dto->quantity
            || ($dto->completed_quantity !== null && ! is_finite($dto->completed_quantity))) {
            throw new BusinessLogicException(trans_message('completed_work.quantity_invalid'), 422);
        }
        if ($dto->completed_quantity !== null && abs($dto->completed_quantity - $dto->quantity) > 0.0000001) {
            throw new BusinessLogicException(trans_message('completed_work.quantity_conflict'), 422);
        }
        $data = array_replace($dto->toArray(), $financialOverrides);
        $data['completed_quantity'] = $dto->quantity;
        unset($data['materials']);
        $data['additional_info'] = $data['additional_info'] ?? [];
        unset($data['additional_info']['financial_calculation'], $data['additional_info']['schedule_auto_draft']);

        if ($data['price'] === null && $data['total_amount'] !== null && $data['quantity'] > 0) {
            $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
        }

        if ($data['total_amount'] === null && $data['price'] !== null) {
            $data['total_amount'] = round($data['price'] * $data['quantity'], 2);
        }

        if ($data['total_amount'] === null && ! empty($dto->materials)) {
            $materialsSum = 0.0;
            foreach ($dto->materials as $material) {
                if ($material instanceof CompletedWorkMaterialDTO) {
                    $materialsSum += $material->total_amount ?? ($material->quantity * ($material->unit_price ?? 0));
                } elseif (is_array($material)) {
                    $materialsSum += (float) ($material['total_amount'] ?? (($material['quantity'] ?? 0) * ($material['unit_price'] ?? 0)));
                }
            }

            if ($materialsSum > 0) {
                $data['total_amount'] = round($materialsSum, 2);
                if ($data['price'] === null && $data['quantity'] > 0) {
                    $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
                }
            }
        }

        if ($applyCoefficients && $data['total_amount'] !== null) {
            $baseTotalAmount = (float) $data['total_amount'];
            $baseUnitPrice = $data['price'];
            $coeff = $this->rateCoefficientService->calculateAdjustedValueDetailed(
                $dto->organization_id,
                (float) $data['total_amount'],
                RateCoefficientAppliesToEnum::WORK_COSTS->value,
                null,
                ['project_id' => $dto->project_id, 'work_type_id' => $dto->work_type_id]
            );
            $data['total_amount'] = $coeff['final'];
            $data['additional_info']['financial_calculation'] = [
                'base_total_amount' => $baseTotalAmount,
                'base_unit_price' => $baseUnitPrice,
                'adjusted_total_amount' => (float) $coeff['final'],
                'applications' => $coeff['applications'] ?? [],
            ];
            if ($data['quantity'] > 0) {
                $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
            }
        }

        return $data;
    }

    private function financialInputsChanged(CompletedWork $existingWork, CompletedWorkDTO $dto): bool
    {
        return abs((float) $dto->quantity - (float) $existingWork->quantity) > 0.0000001
            || $this->nullableFloatChanged($dto->price, $existingWork->price)
            || $this->nullableFloatChanged($dto->total_amount, $existingWork->total_amount);
    }

    private function nullableFloatChanged(?float $value, mixed $existingValue): bool
    {
        if ($value === null && $existingValue === null) {
            return false;
        }

        if ($value === null || $existingValue === null) {
            return true;
        }

        return abs($value - (float) $existingValue) > 0.0000001;
    }

    private function assertCreateSourcePolicy(CompletedWorkDTO $dto): void
    {
        if ($dto->work_origin_type === CompletedWork::ORIGIN_JOURNAL || $dto->journal_entry_id !== null) {
            throw new BusinessLogicException(trans_message('completed_work.origin_immutable'), 422);
        }
        if ($dto->status === CompletedWork::STATUS_CONFIRMED) {
            throw new BusinessLogicException(trans_message('completed_work.confirm_requires_operation'), 422);
        }

        if (! in_array($dto->work_origin_type, [
            CompletedWork::ORIGIN_MANUAL,
            CompletedWork::ORIGIN_SCHEDULE,
            CompletedWork::ORIGIN_JOURNAL,
        ], true)) {
            throw new BusinessLogicException(trans_message('completed_work.invalid_origin'), 422);
        }

        if ($dto->work_origin_type === CompletedWork::ORIGIN_MANUAL && $dto->journal_entry_id !== null) {
            throw new BusinessLogicException(trans_message('completed_work.invalid_origin'), 422);
        }

        if ($dto->work_origin_type === CompletedWork::ORIGIN_SCHEDULE && $dto->schedule_task_id === null) {
            throw new BusinessLogicException(trans_message('completed_work.schedule_origin_requires_task'), 422);
        }

        if ($dto->work_origin_type === CompletedWork::ORIGIN_JOURNAL && $dto->journal_entry_id === null) {
            throw new BusinessLogicException(trans_message('completed_work.journal_origin_requires_entry'), 422);
        }

        $this->assertJournalEntryScope($dto->journal_entry_id, $dto->organization_id, $dto->project_id);
    }

    private function assertUpdateSourcePolicy(CompletedWork $existingWork, CompletedWorkDTO $dto): void
    {
        $existingOrigin = $existingWork->work_origin_type ?? CompletedWork::ORIGIN_MANUAL;

        if ($dto->work_origin_type !== $existingOrigin || $dto->journal_entry_id !== $existingWork->journal_entry_id) {
            throw new BusinessLogicException(trans_message('completed_work.origin_immutable'), 422);
        }

        if ($existingOrigin === CompletedWork::ORIGIN_SCHEDULE && $dto->schedule_task_id === null) {
            throw new BusinessLogicException(trans_message('completed_work.schedule_origin_requires_task'), 422);
        }

        $this->assertJournalEntryScope($dto->journal_entry_id, $dto->organization_id, $dto->project_id);
    }

    private function assertJournalEntryScope(?int $journalEntryId, int $organizationId, int $projectId): void
    {
        if ($journalEntryId === null) {
            return;
        }

        $entry = ConstructionJournalEntry::query()->with('journal')->find($journalEntryId);
        if (! $entry
            || (int) $entry->journal?->organization_id !== $organizationId
            || (int) $entry->journal?->project_id !== $projectId) {
            throw new BusinessLogicException(trans_message('completed_work.journal_entry_not_found'), 422);
        }
    }

    protected function syncMaterials(CompletedWork $completedWork, array $materials): void
    {
        $syncData = [];

        foreach ($materials as $materialData) {
            if ($materialData instanceof CompletedWorkMaterialDTO) {
                $syncData[$materialData->material_id] = $materialData->toArray();
            } elseif (is_array($materialData)) {
                $syncData[$materialData['material_id']] = $materialData;
            }
        }

        $completedWork->materials()->sync($syncData);
    }

    /**
     * Валидация контракта перед добавлением работы
     */
    protected function validateContract(
        int $contractId,
        ?float $workAmount,
        ?int $organizationId = null,
        ?int $projectId = null,
        ?int $contractorId = null
    ): void {
        $contract = Contract::find($contractId);

        if (! $contract) {
            throw new BusinessLogicException('Контракт не найден.', 404);
        }

        if ($organizationId !== null && (int) $contract->organization_id !== (int) $organizationId) {
            throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
        }

        if ($projectId !== null) {
            $allowedProjectIds = $contract->getProjectIds();
            if (($contract->is_multi_project && $allowedProjectIds === [])
                || ($allowedProjectIds !== [] && ! in_array((int) $projectId, array_map('intval', $allowedProjectIds), true))) {
                throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
            }
        }

        if ($contractorId !== null && (int) $contract->contractor_id !== (int) $contractorId) {
            throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
        }

        // Проверка статуса контракта
        if ($contract->status === ContractStatusEnum::COMPLETED) {
            throw ContractException::contractCompleted();
        }

        if ($contract->status === ContractStatusEnum::TERMINATED) {
            throw ContractException::contractTerminated();
        }

        // Проверка лимита суммы
        if ($workAmount && ! $contract->canAddWork($workAmount)) {
            throw ContractException::amountExceedsLimit(
                (float) $contract->completed_works_amount,
                (float) $contract->total_amount,
                (float) $workAmount
            );
        }
    }

    /**
     * Обновление статуса контракта после выполнения работ
     */
    protected function updateContractStatus(int $contractId): void
    {
        $contract = Contract::find($contractId);

        if (! $contract) {
            return;
        }

        // Автоматическое обновление статуса
        $this->contractMutations->syncCompletionStatus($contract, Auth::id(), [
            'origin' => 'completed_work',
        ]);

        // Проверка приближения к лимиту (для уведомлений)
        if ($contract->isNearingLimit()) {
            // Здесь можно добавить логику отправки уведомлений
            // Например, dispatch event или отправить в очередь
            $this->notifyContractNearingLimit($contract);
        }
    }

    /**
     * Уведомление о приближении контракта к лимиту
     */
    protected function notifyContractNearingLimit(Contract $contract): void
    {
        // Логирование
        Log::warning("Контракт #{$contract->number} приближается к лимиту: {$contract->completion_percentage}%", [
            'contract_id' => $contract->id,
            'organization_id' => $contract->organization_id,
            'completed_amount' => $contract->completed_works_amount,
            'total_amount' => $contract->total_amount,
            'completion_percentage' => $contract->completion_percentage,
        ]);

        // Отправляем real-time уведомление
        event(new \App\Events\ContractLimitWarning($contract));

        // Здесь можно добавить отправку email, push-уведомлений и т.д.
    }
}
