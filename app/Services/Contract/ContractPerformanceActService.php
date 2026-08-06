<?php

namespace App\Services\Contract;

use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\CompletedWork;
use App\Models\File;
use App\Repositories\Interfaces\ContractPerformanceActRepositoryInterface;
use App\Services\Acting\ActingQuantityReservationService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventRecorder;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContractPerformanceActService
{
    protected ContractPerformanceActRepositoryInterface $actRepository;

    protected ContractAccessService $contractAccessService;

    protected LoggingService $logging;

    protected FileService $fileService;

    protected ProductionAcceptanceEventRecorder $productionAcceptanceEvents;

    protected ActingQuantityReservationService $quantityReservations;

    public function __construct(
        ContractPerformanceActRepositoryInterface $actRepository,
        ContractAccessService $contractAccessService,
        LoggingService $logging,
        FileService $fileService,
        ProductionAcceptanceEventRecorder $productionAcceptanceEvents,
        ActingQuantityReservationService $quantityReservations,
    ) {
        $this->actRepository = $actRepository;
        $this->contractAccessService = $contractAccessService;
        $this->logging = $logging;
        $this->fileService = $fileService;
        $this->productionAcceptanceEvents = $productionAcceptanceEvents;
        $this->quantityReservations = $quantityReservations;
    }

    protected function getContractOrFail(int $contractId, int $organizationId, ?int $projectId = null): Contract
    {
        $contract = $this->contractAccessService->findAccessible($contractId, $organizationId, $projectId);
        if (! $contract) {
            throw new Exception('Contract not found or does not belong to the organization.');
        }

        // Если указан projectId, проверяем, что контракт принадлежит этому проекту
        if ($projectId !== null) {
            if ($contract->is_multi_project) {
                // Для мультипроектных контрактов проверяем наличие проекта в списке связанных
                // Используем exists() для оптимизации запроса
                $isLinked = $contract->projects()->where('projects.id', $projectId)->exists();

                if (! $isLinked) {
                    throw new Exception('Multi-project contract is not linked to the specified project.');
                }
            } elseif ($contract->project_id !== $projectId) {
                throw new Exception('Contract does not belong to the specified project.');
            }
        }

        return $contract;
    }

    public function getAllActsForContract(int $contractId, int $organizationId, array $filters = [], ?int $projectId = null): Collection
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId); // Проверка, что контракт существует и принадлежит организации

        // Добавляем фильтр по project_id если он передан
        if ($projectId !== null) {
            $filters['project_id'] = $projectId;
        }

        $acts = $this->actRepository->getActsForContract($contractId, $filters);

        // Загружаем связи для каждого акта
        $acts->load(['completedWorks.workType', 'completedWorks.user', 'files.user']);

        return $acts;
    }

    public function createActForContract(int $contractId, int $organizationId, ContractPerformanceActDTO $actDTO, ?int $projectId = null): ContractPerformanceAct
    {
        // Проверка наличия organizationId
        if (! $organizationId) {
            $this->logging->technical('performance_act.creation.failed.missing_organization', [
                'contract_id' => $contractId,
                'project_id' => $projectId,
            ], 'error');

            throw new \InvalidArgumentException('Organization ID is required to create performance act.');
        }

        // BUSINESS: Начало создания акта выполненных работ
        $this->logging->business('performance_act.creation.started', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_document_number' => $actDTO->act_document_number,
            'has_completed_works' => ! empty($actDTO->completed_works),
            'completed_works_count' => count($actDTO->completed_works ?? []),
        ]);

        $contract = $this->getContractOrFail($contractId, $organizationId, $projectId);

        // Выполняем создание акта в транзакции для безопасности
        $act = DB::transaction(function () use ($contract, $actDTO, $projectId) {
            // Создаем акт
            $actData = $actDTO->toArray();
            $actData['contract_id'] = $contract->id;

            // Если project_id не был передан в DTO, но передан в метод - используем его
            if (! isset($actData['project_id']) || $actData['project_id'] === null) {
                $actData['project_id'] = $projectId;
            }

            // Если всё ещё нет project_id - используем из контракта (для обычных контрактов)
            if (! isset($actData['project_id']) || $actData['project_id'] === null) {
                $actData['project_id'] = $contract->project_id;
            }

            // ИСПРАВЛЕНИЕ: Если нет работ, используем amount из DTO, иначе пересчитаем из работ
            if (empty($actDTO->completed_works)) {
                // Если работ нет - используем переданную сумму (или 0 по умолчанию)
                $actData['amount'] = $actDTO->amount;
            } else {
                // Если есть работы - временно 0, будет пересчитано из работ
                $actData['amount'] = 0;
            }

            $act = $this->actRepository->create($actData);

            // Синхронизируем выполненные работы (если есть)
            if (! empty($actDTO->completed_works)) {
                $this->syncCompletedWorks($act, $actDTO->getCompletedWorksForSync());
                // Пересчитываем сумму акта на основе включенных работ
                $act->recalculateAmount();
            }
            if ($act->is_approved || in_array($act->status, [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ], true)) {
                $act->refresh();
                $this->productionAcceptanceEvents->recordTransitionIfApplicable(
                    $act,
                    'pending',
                    'approved',
                    $act->signed_at === null
                        ? CarbonImmutable::now()
                        : CarbonImmutable::instance($act->signed_at),
                    Auth::id(),
                );
            }

            return $act;
        });

        // Сохраняем PDF файл ВНЕ транзакции, чтобы не блокировать создание акта при ошибках загрузки
        if ($actDTO->pdf_file) {
            try {
                $this->saveActPdfFile($act, $actDTO->pdf_file, $organizationId);
            } catch (\Exception $e) {
                // Логируем ошибку, но не блокируем создание акта
                \Illuminate\Support\Facades\Log::error('[ContractPerformanceActService] Failed to upload PDF after act creation', [
                    'act_id' => $act->id,
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Загружаем связи для возврата полных данных
        $act->load(['completedWorks.workType', 'completedWorks.user', 'files.user']);

        // BUSINESS: Акт выполненных работ создан
        $this->logging->business('performance_act.created', [
            'act_id' => $act->id,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_document_number' => $act->act_document_number,
            'final_amount' => $act->amount,
            'included_works_count' => $act->completedWorks()->count(),
            'has_pdf_file' => $actDTO->pdf_file !== null,
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
            'has_pdf_file' => $actDTO->pdf_file !== null,
            'performed_by' => request()->user()?->id,
        ]);

        return $act;
    }

    public function getActById(int $actId, int $contractId, int $organizationId, ?int $projectId = null): ?ContractPerformanceAct
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId);
        $act = $this->actRepository->find($actId);

        // Убедимся, что акт принадлежит указанному контракту и проекту
        if ($act && $act->contract_id === $contractId) {
            // Если указан projectId, проверяем что акт относится к этому проекту
            if ($projectId !== null && $act->project_id !== $projectId) {
                return null;
            }

            // Загружаем связи для возврата полных данных
            $act->load(['completedWorks.workType', 'completedWorks.user', 'files.user']);

            return $act;
        }

        return null;
    }

    public function updateAct(int $actId, int $contractId, int $organizationId, ContractPerformanceActDTO $actDTO, ?int $projectId = null): ContractPerformanceAct
    {
        // BUSINESS: Начало обновления акта
        $this->logging->business('performance_act.update.started', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'new_document_number' => $actDTO->act_document_number,
        ]);

        $this->getContractOrFail($contractId, $organizationId, $projectId);
        $act = $this->actRepository->find($actId);

        if (! $act || $act->contract_id !== $contractId || ($projectId !== null && $act->project_id !== $projectId)) {
            // TECHNICAL: Попытка обновить несуществующий или чужой акт
            $this->logging->technical('performance_act.update.failed.not_found', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'act_exists' => $act !== null,
                'contract_matches' => $act ? ($act->contract_id === $contractId) : false,
                'project_matches' => $act && $projectId ? ($act->project_id === $projectId) : true,
            ], 'warning');

            throw new Exception('Performance act not found or does not belong to the specified contract or project.');
        }

        $oldData = [];

        $updateData = $actDTO->toArray();
        $updated = DB::transaction(function () use (&$oldData, $actDTO, $actId, $updateData): bool {
            $act = ContractPerformanceAct::query()
                ->whereKey($actId)
                ->lockForUpdate()
                ->first();
            if ($act === null) {
                return false;
            }
            $oldData = [
                'amount' => $act->amount,
                'document_number' => $act->act_document_number,
                'is_approved' => $act->is_approved,
            ];
            $wasAccepted = (bool) $act->is_approved || in_array($act->status, [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ], true);
            $isExplicitLegacyReversal = (bool) $act->is_approved
                && ! in_array($act->status, [
                    ContractPerformanceAct::STATUS_APPROVED,
                    ContractPerformanceAct::STATUS_SIGNED,
                ], true)
                && array_key_exists('is_approved', $updateData)
                && $updateData['is_approved'] === false
                && array_diff(array_keys($updateData), ['is_approved', 'approval_date']) === [];
            if ($wasAccepted && $actDTO->completedWorksProvided) {
                throw new BusinessLogicException(trans_message('act_reports.accepted_act_lines_immutable'));
            }
            if ($wasAccepted
                && $actDTO->currency !== null
                && strtoupper($actDTO->currency) !== strtoupper((string) $act->currency)
            ) {
                throw new BusinessLogicException(trans_message('act_reports.accepted_act_lines_immutable'));
            }
            if ($wasAccepted && ! $isExplicitLegacyReversal) {
                throw new BusinessLogicException(trans_message('act_reports.act_already_approved'), 400);
            }

            if ($actDTO->completedWorksProvided) {
                $this->syncCompletedWorks($act, $actDTO->getCompletedWorksForSync());
                $act->recalculateAmount();
            } elseif ($act->completedWorks()->count() > 0) {
                $act->recalculateAmount();
            }

            $updated = $updateData === [] || $this->actRepository->update($actId, $updateData);
            if (!$updated) {
                return false;
            }

            $current = $this->actRepository->find($actId);
            if ($current === null) {
                return false;
            }
            $isAccepted = (bool) $current->is_approved || in_array($current->status, [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ], true);
            if ($wasAccepted !== $isAccepted) {
                $this->productionAcceptanceEvents->recordTransitionIfApplicable(
                    $current,
                    $wasAccepted ? 'approved' : 'pending',
                    $isAccepted ? 'approved' : 'reopened',
                    $isAccepted && $current->signed_at !== null
                        ? CarbonImmutable::instance($current->signed_at)
                        : CarbonImmutable::now(),
                    Auth::id(),
                );
            }

            return true;
        });

        if (! $updated) {
            // TECHNICAL: Ошибка при обновлении в БД
            $this->logging->technical('performance_act.update.failed.database', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
            ], 'error');

            throw new Exception('Failed to update performance act.');
        }

        $act = $this->actRepository->find($actId);

        // Загружаем связи для возврата полных данных
        $act->load(['completedWorks.workType', 'completedWorks.user', 'files.user']);

        // BUSINESS: Акт успешно обновлен
        $this->logging->business('performance_act.updated', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'old_amount' => $oldData['amount'],
            'new_amount' => $act->amount,
            'amount_changed' => $oldData['amount'] != $act->amount,
            'included_works_count' => $act->completedWorks()->count(),
        ]);

        // AUDIT: Критичное изменение финансового документа
        $this->logging->audit('performance_act.updated', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'changes' => [
                'amount' => ['from' => $oldData['amount'], 'to' => $act->amount],
                'document_number' => ['from' => $oldData['document_number'], 'to' => $act->act_document_number],
                'is_approved' => ['from' => $oldData['is_approved'], 'to' => $act->is_approved],
            ],
            'performed_by' => request()->user()?->id,
        ]);

        return $act;
    }

    /**
     * Синхронизировать выполненные работы с актом
     */
    protected function syncCompletedWorks(ContractPerformanceAct $act, array $completedWorksData): void
    {
        $act->loadMissing('contract');
        $organizationId = (int) $act->contract?->organization_id;
        $projectId = (int) $act->project_id;
        // TECHNICAL: Начало синхронизации работ с актом
        $this->logging->technical('performance_act.works.sync.started', [
            'act_id' => $act->id,
            'contract_id' => $act->contract_id,
            'requested_works_count' => count($completedWorksData),
            'work_ids' => array_keys($completedWorksData),
        ]);

        // Проверяем что все работы принадлежат тому же контракту
        $workIds = array_map('intval', array_keys($completedWorksData));
        sort($workIds, SORT_NUMERIC);
        $works = CompletedWork::query()
            ->whereIn('id', $workIds)
            ->where('contract_id', $act->contract_id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'confirmed')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $validWorks = $works->keys()->map(static fn ($id): int => (int) $id)->all();

        $invalidWorks = array_diff($workIds, $validWorks);

        if (! empty($invalidWorks)) {
            // TECHNICAL: Обнаружены невалидные работы
            $this->logging->technical('performance_act.works.sync.invalid_works', [
                'act_id' => $act->id,
                'contract_id' => $act->contract_id,
                'invalid_work_ids' => $invalidWorks,
                'invalid_count' => count($invalidWorks),
                'valid_count' => count($validWorks),
            ], 'warning');

            throw new BusinessLogicException(
                trans_message('act_reports.work_not_available_for_acting'),
                422,
            );
        }

        // Фильтруем только валидные работы
        $filteredData = array_intersect_key($completedWorksData, array_flip($validWorks));
        $availableQuantities = $this->quantityReservations->availableQuantities(
            $works->values(),
            (int) $act->id,
        );
        $this->quantityReservations->assertAvailable(
            array_map(
                static fn (array $work): mixed => $work['included_quantity'] ?? null,
                $filteredData,
            ),
            $availableQuantities,
        );

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
            'removed_count' => count($removedWorks),
        ]);

        // AUDIT: Если были изменения в составе работ - логируем для compliance
        if (! empty($addedWorks) || ! empty($removedWorks)) {
            $this->logging->audit('performance_act.works.modified', [
                'act_id' => $act->id,
                'contract_id' => $act->contract_id,
                'works_changes' => [
                    'added' => $addedWorks,
                    'removed' => $removedWorks,
                ],
                'performed_by' => request()->user()?->id,
            ]);

            // Инвалидируем кэш EVM метрик при изменении состава работ в акте
            $this->invalidateEVMCache($act);
        }
    }

    /**
     * Получить доступные для включения в акт работы по контракту
     */
    public function getAvailableWorksForAct(int $contractId, int $organizationId, ?int $projectId = null): array
    {
        $contract = $this->getContractOrFail($contractId, $organizationId, $projectId);
        $effectiveProjectId = $projectId ?? $contract->project_id;

        // Получаем подтвержденные работы которые еще не включены в утвержденные акты
        $works = \App\Models\CompletedWork::where('contract_id', $contractId)
            ->where('organization_id', $organizationId)
            ->when(
                $effectiveProjectId !== null,
                static fn ($query) => $query->where('project_id', $effectiveProjectId),
            )
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
        return \App\Models\PerformanceActCompletedWork::whereHas('performanceAct', function ($query) {
            $query->where('is_approved', true);
        })->where('completed_work_id', $workId)->exists();
    }

    public function deleteAct(int $actId, int $contractId, int $organizationId, ?int $projectId = null): bool
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId);
        $act = $this->actRepository->find($actId);

        if (! $act || $act->contract_id !== $contractId || ($projectId !== null && $act->project_id !== $projectId)) {
            // SECURITY: Попытка удалить чужой акт - подозрительная активность
            $this->logging->security('performance_act.deletion.unauthorized', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'act_exists' => $act !== null,
                'contract_matches' => $act ? ($act->contract_id === $contractId) : false,
                'project_matches' => $act && $projectId ? ($act->project_id === $projectId) : true,
                'user_id' => request()->user()?->id,
                'attempted_by_ip' => request()->ip(),
            ], 'warning');

            throw new Exception('Performance act not found or does not belong to the specified contract or project.');
        }

        // Сохраняем данные для логирования до удаления
        $actData = [
            'act_id' => $act->id,
            'document_number' => $act->act_document_number,
            'amount' => $act->amount,
            'act_date' => $act->act_date,
            'is_approved' => $act->is_approved,
            'included_works_count' => $act->completedWorks()->count(),
        ];

        // SECURITY: Попытка удаления акта - критичное действие
        $this->logging->security('performance_act.deletion.attempt', [
            'act_id' => $actId,
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'act_amount' => $actData['amount'],
            'is_approved' => $actData['is_approved'],
            'user_id' => request()->user()?->id,
        ], 'warning');

        $result = DB::transaction(function () use ($actId, $contractId, $organizationId, $projectId): bool {
            $lockedAct = ContractPerformanceAct::query()
                ->whereKey($actId)
                ->where('contract_id', $contractId)
                ->when(
                    $projectId !== null,
                    static fn ($query) => $query->where('project_id', $projectId),
                )
                ->whereHas(
                    'contract',
                    static fn ($query) => $query->where('organization_id', $organizationId),
                )
                ->lockForUpdate()
                ->first();
            if ($lockedAct === null) {
                return false;
            }
            if ((bool) $lockedAct->is_approved || in_array($lockedAct->status, [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ], true)) {
                throw new BusinessLogicException(
                    trans_message('act_reports.accepted_act_delete_forbidden'),
                    409,
                );
            }

            return $this->actRepository->delete($actId);
        });

        if ($result) {
            // BUSINESS: Акт успешно удален
            $this->logging->business('performance_act.deleted', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'deleted_amount' => $actData['amount'],
                'was_approved' => $actData['is_approved'],
            ]);

            // AUDIT: Критичное финансовое событие - удаление акта
            $this->logging->audit('performance_act.deleted', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'deleted_act_data' => $actData,
                'performed_by' => request()->user()?->id,
            ]);
        } else {
            // TECHNICAL: Ошибка при удалении
            $this->logging->technical('performance_act.deletion.failed', [
                'act_id' => $actId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
            ], 'error');
        }

        return $result;
    }

    public function getTotalPerformedAmountForContract(int $contractId, int $organizationId, ?int $projectId = null): float
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId);

        return $this->actRepository->getTotalAmountForContract($contractId);
    }

    /**
     * Сохранить PDF файл акта
     */
    protected function saveActPdfFile(ContractPerformanceAct $act, $pdfFile, int $organizationId): ?File
    {
        try {
            // Загружаем файл в S3
            $organization = \App\Models\Organization::find($organizationId);

            if (! $organization) {
                $this->logging->technical('performance_act.pdf_upload.failed', [
                    'act_id' => $act->id,
                    'organization_id' => $organizationId,
                    'reason' => 'Organization not found',
                ], 'error');
                \Illuminate\Support\Facades\Log::error('[ContractPerformanceActService] Organization not found', [
                    'organization_id' => $organizationId,
                    'act_id' => $act->id,
                ]);

                return null;
            }

            $directory = "acts/{$act->id}/documents";

            // Проверяем размер файла перед загрузкой
            $fileSizeMb = round($pdfFile->getSize() / 1024 / 1024, 2);
            $maxFileSizeMb = config('file-uploads.max_sizes.pdf_documents', 100);

            if ($fileSizeMb > $maxFileSizeMb) {
                $this->logging->technical('performance_act.pdf_upload.failed', [
                    'act_id' => $act->id,
                    'organization_id' => $organizationId,
                    'reason' => "File size too large: {$fileSizeMb}MB (max: {$maxFileSizeMb}MB)",
                    'file_size_mb' => $fileSizeMb,
                    'max_size_mb' => $maxFileSizeMb,
                ], 'error');
                \Illuminate\Support\Facades\Log::error('[ContractPerformanceActService] File size too large', [
                    'act_id' => $act->id,
                    'file_size_mb' => $fileSizeMb,
                    'max_size_mb' => $maxFileSizeMb,
                    'config_key' => 'file-uploads.max_sizes.pdf_documents',
                ]);

                return null;
            }

            \Illuminate\Support\Facades\Log::info('[ContractPerformanceActService] Starting PDF upload', [
                'act_id' => $act->id,
                'organization_id' => $organizationId,
                'directory' => $directory,
                'file_size_mb' => $fileSizeMb,
                'original_name' => $pdfFile->getClientOriginalName(),
            ]);

            $path = $this->fileService->upload($pdfFile, $directory, null, 'private', $organization);

            if (! $path) {
                $this->logging->technical('performance_act.pdf_upload.failed', [
                    'act_id' => $act->id,
                    'organization_id' => $organizationId,
                    'reason' => 'FileService returned false',
                    'file_size_mb' => $fileSizeMb,
                    'directory' => $directory,
                    'original_filename' => $pdfFile->getClientOriginalName(),
                ], 'error');

                \Illuminate\Support\Facades\Log::error('[ContractPerformanceActService] FileService upload returned false', [
                    'act_id' => $act->id,
                    'organization_id' => $organizationId,
                    'directory' => $directory,
                    'file_size_mb' => $fileSizeMb,
                    'original_filename' => $pdfFile->getClientOriginalName(),
                ]);

                // Не блокируем создание акта - файл можно загрузить позже
                return null;
            }

            // Создаем запись в таблице files
            $file = File::create([
                'organization_id' => $organizationId,
                'fileable_id' => $act->id,
                'fileable_type' => ContractPerformanceAct::class,
                'user_id' => Auth::id(),
                'name' => basename($path),
                'original_name' => $pdfFile->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $pdfFile->getClientMimeType(),
                'size' => $pdfFile->getSize(),
                'disk' => 's3',
                'type' => 'document',
                'category' => 'act_scan',
                'additional_info' => [
                    'description' => 'Скан акта выполненных работ',
                ],
            ]);

            // TECHNICAL: PDF файл успешно сохранен
            $this->logging->technical('performance_act.pdf_uploaded', [
                'act_id' => $act->id,
                'file_id' => $file->id,
                'file_size_mb' => round($file->size / 1024 / 1024, 2),
                's3_path' => $path,
            ]);

            \Illuminate\Support\Facades\Log::info('[ContractPerformanceActService] PDF uploaded successfully', [
                'act_id' => $act->id,
                'file_id' => $file->id,
                's3_path' => $path,
            ]);

            return $file;

        } catch (\Exception $e) {
            // TECHNICAL: Ошибка при сохранении PDF
            $this->logging->technical('performance_act.pdf_upload.exception', [
                'act_id' => $act->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ], 'error');

            \Illuminate\Support\Facades\Log::error('[ContractPerformanceActService] Exception during PDF upload', [
                'act_id' => $act->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Не блокируем создание акта - файл можно загрузить позже
            return null;
        }
    }

    /**
     * Инвалидировать кэш EVM метрик проекта
     */
    protected function invalidateEVMCache(ContractPerformanceAct $act): void
    {
        try {
            $evmService = app(\App\Services\Analytics\EVMService::class);
            $evmService->invalidateCacheForPerformanceAct($act);

            $this->logging->technical('evm.cache.invalidated', [
                'contract_id' => $act->contract_id,
                'act_id' => $act->id,
                'reason' => 'performance_act_works_modified',
            ]);
        } catch (\Exception $e) {
            // Не критично - логируем и продолжаем
            $this->logging->technical('evm.cache.invalidation_failed', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ], 'warning');
        }
    }
}
