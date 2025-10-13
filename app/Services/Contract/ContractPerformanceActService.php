<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Collection;
use Exception;

class ContractPerformanceActService
{
    protected ContractPerformanceActRepositoryInterface $actRepository;
    protected ContractRepositoryInterface $contractRepository;
    protected LoggingService $logging;

    public function __construct(
        ContractPerformanceActRepositoryInterface $actRepository,
        ContractRepositoryInterface $contractRepository,
        LoggingService $logging
    ) {
        $this->actRepository = $actRepository;
        $this->contractRepository = $contractRepository;
        $this->logging = $logging;
    }

    protected function getContractOrFail(int $contractId, int $organizationId): Contract
    {
        $contract = $this->contractRepository->findAccessible($contractId, $organizationId);
        if (!$contract) {
            throw new Exception('Contract not found or does not belong to the organization.');
        }
        return $contract;
    }

    public function getAllActsForContract(int $contractId, int $organizationId, array $filters = []): Collection
    {
        $this->getContractOrFail($contractId, $organizationId); // Проверка, что контракт существует и принадлежит организации
        return $this->actRepository->getActsForContract($contractId, $filters);
    }

    public function createActForContract(int $contractId, int $organizationId, ContractPerformanceActDTO $actDTO): ContractPerformanceAct
    {
        // BUSINESS: Начало создания акта выполненных работ
        $this->logging->business('performance_act.creation.started', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_document_number' => $actDTO->act_document_number,
            'has_completed_works' => !empty($actDTO->completed_works),
            'completed_works_count' => count($actDTO->completed_works ?? [])
        ]);

        $contract = $this->getContractOrFail($contractId, $organizationId);

        // Создаем акт со значением суммы по умолчанию (будет пересчитана на основе работ)
        $actData = $actDTO->toArray();
        $actData['contract_id'] = $contract->id;
        $actData['amount'] = 0; // Временная сумма, будет пересчитана

        $act = $this->actRepository->create($actData);

        // Синхронизируем выполненные работы (обязательно для создания акта)
        if (!empty($actDTO->completed_works)) {
            $this->syncCompletedWorks($act, $actDTO->getCompletedWorksForSync());
            // Пересчитываем сумму акта на основе включенных работ
            $act->recalculateAmount();
        }

        // BUSINESS: Акт выполненных работ создан
        $this->logging->business('performance_act.created', [
            'act_id' => $act->id,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_document_number' => $act->act_document_number,
            'final_amount' => $act->amount,
            'included_works_count' => $act->completedWorks()->count()
        ]);

        // AUDIT: Критичное финансовое событие - создание акта
        $this->logging->audit('performance_act.created', [
            'act_id' => $act->id,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_document_number' => $act->act_document_number,
            'amount' => $act->amount,
            'act_date' => $act->act_date,
            'is_approved' => $act->is_approved,
            'performed_by' => request()->user()?->id
        ]);

        return $act;
    }

    public function getActById(int $actId, int $contractId, int $organizationId): ?ContractPerformanceAct
    {
        $this->getContractOrFail($contractId, $organizationId);
        $act = $this->actRepository->find($actId);
        // Убедимся, что акт принадлежит указанному контракту
        if ($act && $act->contract_id === $contractId) {
            return $act;
        }
        return null;
    }

    public function updateAct(int $actId, int $contractId, int $organizationId, ContractPerformanceActDTO $actDTO): ContractPerformanceAct
    {
        // BUSINESS: Начало обновления акта
        $this->logging->business('performance_act.update.started', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'new_document_number' => $actDTO->act_document_number
        ]);

        $this->getContractOrFail($contractId, $organizationId);
        $act = $this->actRepository->find($actId);

        if (!$act || $act->contract_id !== $contractId) {
            // TECHNICAL: Попытка обновить несуществующий или чужой акт
            $this->logging->technical('performance_act.update.failed.not_found', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'act_exists' => $act !== null,
                'contract_matches' => $act ? ($act->contract_id === $contractId) : false
            ], 'warning');

            throw new Exception('Performance act not found or does not belong to the specified contract.');
        }

        $oldData = [
            'amount' => $act->amount,
            'document_number' => $act->act_document_number,
            'is_approved' => $act->is_approved
        ];

        $updateData = $actDTO->toArray();
        $updated = $this->actRepository->update($actId, $updateData);

        if (!$updated) {
            // TECHNICAL: Ошибка при обновлении в БД
            $this->logging->technical('performance_act.update.failed.database', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId
            ], 'error');

            throw new Exception('Failed to update performance act.');
        }

        $act = $this->actRepository->find($actId);

        // Синхронизируем выполненные работы если они переданы
        if (isset($actDTO->completed_works) && !empty($actDTO->completed_works)) {
            $this->syncCompletedWorks($act, $actDTO->getCompletedWorksForSync());
        }

        // Всегда пересчитываем сумму акта на основе включенных работ
        $act->recalculateAmount();

        // BUSINESS: Акт успешно обновлен
        $this->logging->business('performance_act.updated', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'old_amount' => $oldData['amount'],
            'new_amount' => $act->amount,
            'amount_changed' => $oldData['amount'] != $act->amount,
            'included_works_count' => $act->completedWorks()->count()
        ]);

        // AUDIT: Критичное изменение финансового документа
        $this->logging->audit('performance_act.updated', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'changes' => [
                'amount' => ['from' => $oldData['amount'], 'to' => $act->amount],
                'document_number' => ['from' => $oldData['document_number'], 'to' => $act->act_document_number],
                'is_approved' => ['from' => $oldData['is_approved'], 'to' => $act->is_approved]
            ],
            'performed_by' => request()->user()?->id
        ]);

        return $act;
    }

    /**
     * Синхронизировать выполненные работы с актом
     */
    protected function syncCompletedWorks(ContractPerformanceAct $act, array $completedWorksData): void
    {
        // TECHNICAL: Начало синхронизации работ с актом
        $this->logging->technical('performance_act.works.sync.started', [
            'act_id' => $act->id,
            'contract_id' => $act->contract_id,
            'requested_works_count' => count($completedWorksData),
            'work_ids' => array_keys($completedWorksData)
        ]);

        // Проверяем что все работы принадлежат тому же контракту
        $workIds = array_keys($completedWorksData);
        $validWorks = \App\Models\CompletedWork::whereIn('id', $workIds)
            ->where('contract_id', $act->contract_id)
            ->where('status', 'confirmed') // Только подтвержденные работы можно включать в акты
            ->pluck('id')
            ->toArray();

        $invalidWorks = array_diff($workIds, $validWorks);
        
        if (!empty($invalidWorks)) {
            // TECHNICAL: Обнаружены невалидные работы
            $this->logging->technical('performance_act.works.sync.invalid_works', [
                'act_id' => $act->id,
                'contract_id' => $act->contract_id,
                'invalid_work_ids' => $invalidWorks,
                'invalid_count' => count($invalidWorks),
                'valid_count' => count($validWorks)
            ], 'warning');
        }

        // Фильтруем только валидные работы
        $filteredData = array_intersect_key($completedWorksData, array_flip($validWorks));

        // Получаем текущие работы для сравнения
        $currentWorkIds = $act->completedWorks()->pluck('completed_work_id')->toArray();

        // Синхронизируем связи
        $act->completedWorks()->sync($filteredData);

        $newWorkIds = array_keys($filteredData);
        $addedWorks = array_diff($newWorkIds, $currentWorkIds);
        $removedWorks = array_diff($currentWorkIds, $newWorkIds);

        // TECHNICAL: Результат синхронизации
        $this->logging->technical('performance_act.works.sync.completed', [
            'act_id' => $act->id,
            'contract_id' => $act->contract_id,
            'synced_works_count' => count($filteredData),
            'added_works' => $addedWorks,
            'removed_works' => $removedWorks,
            'added_count' => count($addedWorks),
            'removed_count' => count($removedWorks)
        ]);

        // AUDIT: Если были изменения в составе работ - логируем для compliance
        if (!empty($addedWorks) || !empty($removedWorks)) {
            $this->logging->audit('performance_act.works.modified', [
                'act_id' => $act->id,
                'contract_id' => $act->contract_id,
                'works_changes' => [
                    'added' => $addedWorks,
                    'removed' => $removedWorks
                ],
                'performed_by' => request()->user()?->id
            ]);
        }
    }

    /**
     * Получить доступные для включения в акт работы по контракту
     */
    public function getAvailableWorksForAct(int $contractId, int $organizationId): array
    {
        $this->getContractOrFail($contractId, $organizationId);
        
        // Получаем подтвержденные работы которые еще не включены в утвержденные акты
        $works = \App\Models\CompletedWork::where('contract_id', $contractId)
            ->where('status', 'confirmed')
            ->with(['workType:id,name', 'user:id,name'])
            ->get();

        return $works->map(function ($work) {
            return [
                'id' => $work->id,
                'work_type_name' => $work->workType->name ?? 'Не указано',
                'user_name' => $work->user->name ?? 'Не указано',
                'quantity' => (float) $work->quantity,
                'price' => (float) $work->price,
                'total_amount' => (float) $work->total_amount,
                'completion_date' => $work->completion_date,
                'is_included_in_approved_act' => $this->isWorkIncludedInApprovedAct($work->id),
            ];
        })->toArray();
    }

    /**
     * Проверить включена ли работа в утвержденный акт
     */
    protected function isWorkIncludedInApprovedAct(int $workId): bool
    {
        return \App\Models\PerformanceActCompletedWork::whereHas('performanceAct', function($query) {
            $query->where('is_approved', true);
        })->where('completed_work_id', $workId)->exists();
    }

    public function deleteAct(int $actId, int $contractId, int $organizationId): bool
    {
        $this->getContractOrFail($contractId, $organizationId);
        $act = $this->actRepository->find($actId);

        if (!$act || $act->contract_id !== $contractId) {
            // SECURITY: Попытка удалить чужой акт - подозрительная активность
            $this->logging->security('performance_act.deletion.unauthorized', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'act_exists' => $act !== null,
                'contract_matches' => $act ? ($act->contract_id === $contractId) : false,
                'user_id' => request()->user()?->id,
                'attempted_by_ip' => request()->ip()
            ], 'warning');
            
            throw new Exception('Performance act not found or does not belong to the specified contract.');
        }

        // Сохраняем данные для логирования до удаления
        $actData = [
            'act_id' => $act->id,
            'document_number' => $act->act_document_number,
            'amount' => $act->amount,
            'act_date' => $act->act_date,
            'is_approved' => $act->is_approved,
            'included_works_count' => $act->completedWorks()->count()
        ];

        // SECURITY: Попытка удаления акта - критичное действие
        $this->logging->security('performance_act.deletion.attempt', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_amount' => $actData['amount'],
            'is_approved' => $actData['is_approved'],
            'user_id' => request()->user()?->id
        ], 'warning');

        $result = $this->actRepository->delete($actId);
        
        if ($result) {
            // BUSINESS: Акт успешно удален
            $this->logging->business('performance_act.deleted', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'deleted_amount' => $actData['amount'],
                'was_approved' => $actData['is_approved']
            ]);

            // AUDIT: Критичное финансовое событие - удаление акта
            $this->logging->audit('performance_act.deleted', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'deleted_act_data' => $actData,
                'performed_by' => request()->user()?->id
            ]);
        } else {
            // TECHNICAL: Ошибка при удалении
            $this->logging->technical('performance_act.deletion.failed', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId
            ], 'error');
        }

        return $result;
    }
    
    public function getTotalPerformedAmountForContract(int $contractId, int $organizationId): float
    {
        $this->getContractOrFail($contractId, $organizationId);
        return $this->actRepository->getTotalAmountForContract($contractId);
    }
} 