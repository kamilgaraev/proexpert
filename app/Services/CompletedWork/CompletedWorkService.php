<?php

namespace App\Services\CompletedWork;

use App\Models\CompletedWork;
use App\Models\Project;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectContextService;
use App\Domain\Project\ValueObjects\ProjectContext;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\ContractException;
use App\Models\Contract;
use App\Enums\Contract\ContractStatusEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Rules\ProjectAccessibleRule;
use App\Services\RateCoefficient\RateCoefficientService;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;

class CompletedWorkService
{
    protected CompletedWorkRepositoryInterface $completedWorkRepository;
    protected RateCoefficientService $rateCoefficientService;
    protected LoggingService $logging;
    protected ProjectContextService $projectContextService;

    public function __construct(
        CompletedWorkRepositoryInterface $completedWorkRepository,
        RateCoefficientService $rateCoefficientService,
        LoggingService $logging,
        ProjectContextService $projectContextService
    ) {
        $this->completedWorkRepository = $completedWorkRepository;
        $this->rateCoefficientService = $rateCoefficientService;
        $this->logging = $logging;
        $this->projectContextService = $projectContextService;
    }

    public function getAll(array $filters = [], int $perPage = 15, string $sortBy = 'completion_date', string $sortDirection = 'desc', array $relations = []): LengthAwarePaginator
    {
        // Добавляем сортировку по умолчанию для выполненных работ
        return $this->completedWorkRepository->getAllPaginated($filters, $perPage, $sortBy, $sortDirection, $relations);
    }

    public function getById(int $id, int $organizationId): CompletedWork
    {
        $completedWork = $this->completedWorkRepository->findById($id, $organizationId);
        if (!$completedWork) {
            throw new BusinessLogicException('Запись о выполненной работе не найдена.', 404);
        }
        return $completedWork;
    }

    public function create(CompletedWorkDTO $dto, ?ProjectContext $projectContext = null): CompletedWork
    {
        // Project-Based RBAC: валидация прав и auto-fill contractor_org_id
        if ($projectContext) {
            // Проверка: может ли роль создавать работы
            if (!$projectContext->roleConfig->canManageWorks) {
                throw new BusinessLogicException(
                    'Ваша роль "' . $projectContext->roleConfig->displayLabel . 
                    '" не позволяет создавать работы в этом проекте',
                    403
                );
            }

            // Auto-fill contractor_id для contractor/subcontractor ролей
            $contractorId = $dto->contractor_id;
            
            if (in_array($projectContext->roleConfig->role->value, ['contractor', 'subcontractor'])) {
                // Проверяем: если пытаются указать другого подрядчика - ошибка
                if ($contractorId && $contractorId !== $projectContext->organizationId) {
                    throw new BusinessLogicException('Подрядчик может создавать работы только для себя', 403);
                }
                
                // Auto-fill
                $contractorId = $projectContext->organizationId;
                
                // Создаем новый DTO с исправленным contractor_id
                $dto = new CompletedWorkDTO(
                    id: $dto->id,
                    organization_id: $dto->organization_id,
                    project_id: $dto->project_id,
                    contract_id: $dto->contract_id,
                    contractor_id: $contractorId,
                    work_type_id: $dto->work_type_id,
                    user_id: $dto->user_id,
                    quantity: $dto->quantity,
                    price: $dto->price,
                    total_amount: $dto->total_amount,
                    completion_date: $dto->completion_date,
                    notes: $dto->notes,
                    status: $dto->status,
                    additional_info: $dto->additional_info,
                    materials: $dto->materials
                );
                
                $this->logging->technical('contractor_id auto-filled', [
                    'organization_id' => $dto->organization_id,
                    'contractor_id' => $contractorId,
                    'role' => $projectContext->roleConfig->role->value,
                ]);
            }

            // Валидация: contractor должен быть участником проекта
            if ($contractorId) {
                $project = Project::find($dto->project_id);
                
                if (!$project) {
                    throw new BusinessLogicException('Проект не найден', 404);
                }
                
                $contractorInProject = $project->hasOrganization($contractorId);
                
                if (!$contractorInProject) {
                    throw new BusinessLogicException(
                        'Организация-подрядчик не является участником проекта. ' .
                        'Сначала добавьте её в список участников.',
                        422
                    );
                }
            }
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
            'has_materials' => !empty($dto->materials),
            'materials_count' => count($dto->materials ?? []),
            'has_project_context' => $projectContext !== null,
        ]);

        return DB::transaction(function () use ($dto) {
            // Проверяем, доступен ли проект для текущей организации безопасности
            $rule = new ProjectAccessibleRule();
            if (!$rule->passes('project_id', $dto->project_id)) {
                // SECURITY: Попытка создать работу для недоступного проекта
                $this->logging->security('completed_work.creation.unauthorized', [
                    'project_id' => $dto->project_id,
                    'organization_id' => $dto->organization_id,
                    'user_id' => request()->user()?->id,
                    'attempted_by_ip' => request()->ip()
                ], 'warning');
                
                throw new BusinessLogicException('Проект недоступен для вашей организации.', 422);
            }

            // Валидация контракта перед созданием работы
            if ($dto->contract_id) {
                $this->validateContract($dto->contract_id, $dto->total_amount);
            }

            $data = $dto->toArray();
            unset($data['materials']);

            // Автовычисление цены / суммы
            if ($data['price'] === null && $data['total_amount'] !== null && $data['quantity'] > 0) {
                $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
            }

            if ($data['total_amount'] === null && $data['price'] !== null) {
                $data['total_amount'] = round($data['price'] * $data['quantity'], 2);
            }

            // Если всё ещё нет суммы, пытаемся рассчитать из материалов
            if ($data['total_amount'] === null && !empty($dto->materials)) {
                $materialsSum = 0;
                foreach ($dto->materials as $m) {
                    if ($m instanceof \App\DTOs\CompletedWork\CompletedWorkMaterialDTO) {
                        $materialsSum += $m->total_amount ?? ($m->quantity * ($m->unit_price ?? 0));
                    } elseif (is_array($m)) {
                        $materialsSum += $m['total_amount'] ?? ($m['quantity'] * ($m['unit_price'] ?? 0));
                    }
                }
                if ($materialsSum > 0) {
                    $data['total_amount'] = round($materialsSum, 2);
                    if ($data['price'] === null && $data['quantity'] > 0) {
                        $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
                    }
                }
            }

            // Применяем коэффициенты к стоимости работ, если рассчитана сумма
            if (isset($data['total_amount'])) {
                $coeff = $this->rateCoefficientService->calculateAdjustedValueDetailed(
                    $dto->organization_id,
                    $data['total_amount'],
                    RateCoefficientAppliesToEnum::WORK_COSTS->value,
                    null,
                    ['project_id' => $dto->project_id, 'work_type_id' => $dto->work_type_id]
                );
                $data['total_amount'] = $coeff['final'];
                if ($data['quantity'] > 0) {
                    $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
                }
            }

            $createdModel = $this->completedWorkRepository->create($data);

            if (!$createdModel) {
                // TECHNICAL: Ошибка создания записи в БД
                $this->logging->technical('completed_work.creation.failed.database', [
                    'project_id' => $dto->project_id,
                    'contract_id' => $dto->contract_id,
                    'organization_id' => $dto->organization_id
                ], 'error');
                
                throw new BusinessLogicException('Не удалось создать запись о выполненной работе.', 500);
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
                'has_materials' => $createdModel->materials()->count() > 0
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
                'performed_by' => request()->user()?->id
            ]);

            return $createdModel->fresh(['materials.measurementUnit']);
        });
    }

    public function update(int $id, CompletedWorkDTO $dto): CompletedWork
    {
        return DB::transaction(function () use ($id, $dto) {
            $existingWork = $this->getById($id, $dto->organization_id);

            // Валидация контракта при изменении суммы или статуса
            if ($dto->contract_id && ($dto->total_amount !== $existingWork->total_amount || $dto->status !== $existingWork->status)) {
                // Расчет разницы в сумме
                $amountDifference = ($dto->total_amount ?? 0) - ($existingWork->total_amount ?? 0);
                
                if ($amountDifference > 0) {
                    $this->validateContract($dto->contract_id, $amountDifference);
                }
            }

            $data = $dto->toArray();
            unset($data['materials']);

            if ($data['price'] === null && $data['total_amount'] !== null && $data['quantity'] > 0) {
                $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
            }

            if ($data['total_amount'] === null && $data['price'] !== null) {
                $data['total_amount'] = round($data['price'] * $data['quantity'], 2);
            }

            if ($data['total_amount'] === null && !empty($dto->materials)) {
                $materialsSum = 0;
                foreach ($dto->materials as $m) {
                    if ($m instanceof \App\DTOs\CompletedWork\CompletedWorkMaterialDTO) {
                        $materialsSum += $m->total_amount ?? ($m->quantity * ($m->unit_price ?? 0));
                    } elseif (is_array($m)) {
                        $materialsSum += $m['total_amount'] ?? ($m['quantity'] * ($m['unit_price'] ?? 0));
                    }
                }
                if ($materialsSum > 0) {
                    $data['total_amount'] = round($materialsSum, 2);
                    if ($data['price'] === null && $data['quantity'] > 0) {
                        $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
                    }
                }
            }

            // Применяем коэффициенты к стоимости работ
            if (isset($data['total_amount'])) {
                $coeff = $this->rateCoefficientService->calculateAdjustedValueDetailed(
                    $dto->organization_id,
                    $data['total_amount'],
                    RateCoefficientAppliesToEnum::WORK_COSTS->value,
                    null,
                    ['project_id' => $dto->project_id, 'work_type_id' => $dto->work_type_id]
                );
                $data['total_amount'] = $coeff['final'];
                if ($data['quantity'] > 0) {
                    $data['price'] = round($data['total_amount'] / $data['quantity'], 2);
                }
            }

            $success = $this->completedWorkRepository->update($id, $data);
            if (!$success) {
                throw new BusinessLogicException('Не удалось обновить запись о выполненной работе.', 500);
            }

            $updatedWork = $existingWork->refresh();

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

    public function delete(int $id, int $organizationId): bool
    {
        $this->getById($id, $organizationId);
        
        $success = $this->completedWorkRepository->delete($id);
        if (!$success) {
            throw new BusinessLogicException('Не удалось удалить запись о выполненной работе.', 500);
        }
        return true;
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

    public function syncCompletedWorkMaterials(int $completedWorkId, array $materials, int $organizationId): CompletedWork
    {
        $completedWork = $this->getById($completedWorkId, $organizationId);
        
        $this->syncMaterials($completedWork, $materials);
        
        return $completedWork->fresh(['materials.measurementUnit']);
    }

    public function getWorkTypeMaterialDefaults(int $workTypeId, int $organizationId): Collection
    {
        return DB::table('work_type_materials as wtm')
            ->join('materials as m', 'wtm.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('wtm.work_type_id', $workTypeId)
            ->where('wtm.organization_id', $organizationId)
            ->whereNull('wtm.deleted_at')
            ->whereNull('m.deleted_at')
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'm.default_price',
                'wtm.default_quantity as quantity',
                'wtm.notes',
                'mu.short_name as measurement_unit'
            ])
            ->get();
    }

    /**
     * Валидация контракта перед добавлением работы
     */
    protected function validateContract(int $contractId, ?float $workAmount): void
    {
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            throw new BusinessLogicException('Контракт не найден.', 404);
        }

        // Проверка статуса контракта
        if ($contract->status === ContractStatusEnum::COMPLETED) {
            throw ContractException::contractCompleted();
        }

        if ($contract->status === ContractStatusEnum::TERMINATED) {
            throw ContractException::contractTerminated();
        }

        // Проверка лимита суммы
        if ($workAmount && !$contract->canAddWork($workAmount)) {
            throw ContractException::amountExceedsLimit(
                $contract->completed_works_amount,
                $contract->total_amount,
                $workAmount
            );
        }
    }

    /**
     * Обновление статуса контракта после выполнения работ
     */
    protected function updateContractStatus(int $contractId): void
    {
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            return;
        }

        // Автоматическое обновление статуса
        $statusChanged = $contract->updateStatusBasedOnCompletion();

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
            'completion_percentage' => $contract->completion_percentage
        ]);

        // Отправляем real-time уведомление
        event(new \App\Events\ContractLimitWarning($contract));

        // Здесь можно добавить отправку email, push-уведомлений и т.д.
    }
} 