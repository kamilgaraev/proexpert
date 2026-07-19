<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Services\Logging\LoggingService;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContractService
{
    protected ContractRepositoryInterface $contractRepository;

    protected LoggingService $logging;

    protected ContractAccessService $contractAccessService;

    protected ContractSideMutationService $contractSideMutationService;

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        LoggingService $logging,
        ContractAccessService $contractAccessService,
        ContractSideMutationService $contractSideMutationService
    ) {
        $this->contractRepository = $contractRepository;
        $this->logging = $logging;
        $this->contractAccessService = $contractAccessService;
        $this->contractSideMutationService = $contractSideMutationService;
    }

    public function getAllContracts(int $organizationId, int $perPage = 15, array $filters = [], string $sortBy = 'date', string $sortDirection = 'desc'): LengthAwarePaginator
    {
        return $this->contractRepository->getContractsForOrganizationPaginated($organizationId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function createContract(
        int $organizationId,
        ContractDTO $contractDTO,
        ?ProjectContext $projectContext = null
    ): Contract {
        return $this->contractSideMutationService->create($organizationId, $contractDTO, $projectContext);
    }

    public function getContractById(
        int $contractId,
        int $organizationId,
        ?int $projectId = null
    ): ?Contract {
        return $this->contractAccessService->findAccessible($contractId, $organizationId, $projectId);
    }

    public function updateContract(
        int $contractId,
        int $organizationId,
        ContractDTO $contractDTO
    ): Contract {
        return $this->contractSideMutationService->update($contractId, $organizationId, $contractDTO);
    }

    public function resolveSideReview(int $contractId, int $organizationId, ContractSideTypeEnum $contractSideType): Contract
    {
        return $this->contractSideMutationService->resolveReview($contractId, $organizationId, $contractSideType);
    }

    public function deleteContract(int $contractId, int $organizationId): bool
    {
        $contract = $this->getContractById($contractId, $organizationId);
        if (! $contract) {
            throw new Exception('Contract not found or does not belong to organization.');
        }

        // SECURITY: Попытка удаления договора - критично для аудита
        // Получаем количество платежей из таблицы payment_documents
        $paymentsCount = DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->where('invoiceable_id', $contractId)
            ->whereNull('deleted_at')
            ->count();

        $this->logging->security('contract.deletion.attempt', [
            'organization_id' => $organizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'contract_amount' => $contract->total_amount,
            'contract_status' => $contract->status->value ?? $contract->status,
            'has_performance_acts' => $contract->performanceActs->count() > 0,
            'has_payments' => $paymentsCount > 0,
            'user_id' => Auth::id(),
            'user_ip' => request()->ip(),
        ], 'warning');

        // BUSINESS: Начало удаления договора
        $this->logging->business('contract.deletion.started', [
            'organization_id' => $organizationId,
            'contract_id' => $contractId,
            'contract_number' => $contract->number,
            'contract_amount' => $contract->total_amount,
            'related_acts_count' => $contract->performanceActs->count(),
            'related_payments_count' => $paymentsCount,
            'user_id' => Auth::id(),
        ]);

        try {
            // Возможно, стоит проверить наличие связанных актов/платежей перед удалением
            // или настроить каскадное удаление/soft deletes на уровне БД
            $deleted = $this->contractRepository->delete($contract->id);

            if ($deleted) {
                // BUSINESS: Договор успешно удалён
                $this->logging->business('contract.deleted', [
                    'organization_id' => $organizationId,
                    'contract_id' => $contractId,
                    'contract_number' => $contract->number,
                    'contract_amount' => $contract->total_amount,
                    'user_id' => Auth::id(),
                ]);

                // AUDIT: Удаление договора - критично для compliance
                $this->logging->audit('contract.deleted', [
                    'organization_id' => $organizationId,
                    'project_id' => $contract->project_id,
                    'contract_id' => $contractId,
                    'contract_number' => $contract->number,
                    'transaction_type' => 'contract_deleted',
                    'performed_by' => Auth::id() ?? 'system',
                    'contract_details' => [
                        'total_amount' => $contract->total_amount,
                        'contractor_id' => $contract->contractor_id,
                        'status' => $contract->status->value ?? $contract->status,
                        'start_date' => $contract->start_date,
                        'end_date' => $contract->end_date,
                    ],
                ]);
            }

            return $deleted;

        } catch (Exception $e) {
            // BUSINESS: Неудачное удаление договора
            $this->logging->business('contract.deletion.failed', [
                'organization_id' => $organizationId,
                'contract_id' => $contractId,
                'contract_number' => $contract->number,
                'error_message' => $e->getMessage(),
                'user_id' => Auth::id(),
            ], 'error');

            throw $e;
        }
    }

    /**
     * Получить полную детальную информацию по контракту
     */
    public function getFullContractDetails(int $contractId, int $organizationId, ?int $projectId = null): array
    {
        $contract = $this->getContractById($contractId, $organizationId, $projectId);

        if (! $contract) {
            throw new Exception('Contract not found or does not belong to organization.');
        }

        // Загружаем все связи одним запросом
        $contract->load([
            'contractor:id,name,legal_address,inn,kpp,phone,email',
            'project:id,name,address,description,organization_id',
            'project.organization:id,name,inn,kpp,legal_address,contact_email,contact_phone',
            'project.organizations:id,name,tax_number,email,phone',
            'parentContract:id,number,total_amount,status',
            // 'payments' - УДАЛЕНО: платежи теперь в модуле Payments (invoices)
            'completedWorks:id,contract_id,work_type_id,user_id,quantity,total_amount,status,completion_date',
            'completedWorks.workType:id,name',
            'completedWorks.user:id,name',
            'completedWorks.materials',
            'agreements:id,contract_id,number,agreement_date,change_amount,subject_changes,supersede_agreement_ids,created_at,updated_at',
            'specifications:id,number,spec_date,total_amount,status,scope_items',
        ]);

        // Загружаем акты с фильтрацией по project_id если указан
        if ($projectId !== null) {
            $contract->load(['performanceActs' => function ($query) use ($projectId) {
                $query->where('project_id', $projectId)
                    ->select('id', 'contract_id', 'project_id', 'act_document_number', 'act_date', 'amount', 'description', 'is_approved', 'approval_date');
            },
                'performanceActs.completedWorks:id,work_type_id,user_id,quantity,total_amount,status,completion_date',
                'performanceActs.completedWorks.workType:id,name',
                'performanceActs.completedWorks.user:id,name']);
        } else {
            $contract->load([
                'performanceActs:id,contract_id,project_id,act_document_number,act_date,amount,description,is_approved,approval_date',
                'performanceActs.completedWorks:id,work_type_id,user_id,quantity,total_amount,status,completion_date',
                'performanceActs.completedWorks.workType:id,name',
                'performanceActs.completedWorks.user:id,name',
            ]);
        }

        // Принудительно загружаем дочерние контракты отдельно
        $contract->setRelation('childContracts',
            Contract::where('parent_contract_id', $contract->id)
                ->where('organization_id', $contract->organization_id)
                ->select('id', 'number', 'total_amount', 'status')
                ->get()
        );

        // TECHNICAL: Диагностика дочерних контрактов для управленческих отчетов
        $dbChildren = \App\Models\Contract::where('parent_contract_id', $contract->id)->get();
        $this->logging->technical('contract.child_contracts.diagnostic', [
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'contract_org_id' => $contract->organization_id,
            'childContracts_loaded' => $contract->relationLoaded('childContracts'),
            'childContracts_count' => $contract->childContracts->count(),
            'childContracts_ids' => $contract->childContracts->pluck('id')->toArray(),
            'db_children_count' => $dbChildren->count(),
            'db_children_details' => $dbChildren->map(function ($child) {
                return [
                    'id' => $child->id,
                    'number' => $child->number,
                    'organization_id' => $child->organization_id,
                    'parent_contract_id' => $child->parent_contract_id,
                    'deleted_at' => $child->deleted_at,
                ];
            })->toArray(),
            'user_id' => Auth::id(),
        ]);

        // Аналитика на основе загруженных связей (не новых запросов!)
        $analytics = $this->buildContractAnalyticsFromLoaded($contract);

        // Статистика по работам из уже загруженной коллекции
        $worksStatistics = $this->buildWorksStatisticsFromLoaded($contract);

        // Форматируем уже загруженные работы
        $recentWorks = $this->formatCompletedWorksFromLoaded($contract);

        return [
            'contract' => $contract,
            'analytics' => $analytics,
            'works_statistics' => $worksStatistics,
            'recent_works' => $recentWorks,
        ];
    }

    /**
     * Построить аналитические данные из уже загруженных связей
     */
    private function buildContractAnalyticsFromLoaded(Contract $contract): array
    {
        // Работаем только с уже загруженными данными
        $confirmedWorks = $contract->completedWorks->where('status', 'confirmed');
        $pendingWorks = $contract->completedWorks->where('status', 'pending');
        $approvedActs = $contract->performanceActs->where('is_approved', true);

        $completedWorksAmount = $confirmedWorks->sum('total_amount');

        // Получаем оплаченную сумму и количество платежей из таблицы payment_documents
        $documentsQuery = DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->where('invoiceable_id', $contract->id)
            ->whereNull('deleted_at');

        $totalPaidAmount = $documentsQuery->sum('paid_amount');
        $paymentsCount = $documentsQuery->count();

        // --- Расширяем стоимость контракта ---
        $agreementsDelta = $contract->relationLoaded('agreements') ? $contract->agreements->sum('change_amount') : 0;
        $childContractsTotal = $contract->relationLoaded('childContracts') ? $contract->childContracts->sum('total_amount') : 0;
        $specificationsTotal = $contract->relationLoaded('specifications') ? $contract->specifications->sum('total_amount') : 0;

        // Итоговая «стоимость контракта» с учётом доп. соглашений, дочерних контрактов и спецификаций
        $aggregatedContractAmount = (float) $contract->total_amount + (float) $agreementsDelta + (float) $childContractsTotal + (float) $specificationsTotal;

        // Расчет ГП: используем accessor модели для правильного расчета от base_amount
        $gpPercentage = (float) $contract->gp_percentage;
        $gpAmountAgg = (float) $contract->gp_amount; // Accessor рассчитывает от base_amount
        $totalWithGpAgg = (float) $contract->total_amount_with_gp;

        // Новый расчёт суммы актов на основе включённых работ
        $totalPerformedAmount = $this->calculateActualPerformedAmount($approvedActs);

        return [
            'financial' => [
                'total_amount' => $aggregatedContractAmount,
                'base_contract_amount' => (float) $contract->total_amount,
                'agreements_delta' => (float) $agreementsDelta,
                'child_contracts_amount' => (float) $childContractsTotal,
                'specifications_amount' => (float) $specificationsTotal,
                'gp_percentage' => $gpPercentage,
                'gp_amount' => $gpAmountAgg,
                'total_amount_with_gp' => $totalWithGpAgg,
                'completed_works_amount' => (float) $completedWorksAmount,
                'remaining_amount' => (float) max(0, $aggregatedContractAmount - $totalPerformedAmount),
                'completion_percentage' => $aggregatedContractAmount > 0 ?
                    round(($totalPerformedAmount / $aggregatedContractAmount) * 100, 2) : 0.0,
                'total_paid_amount' => (float) $totalPaidAmount,
                'total_performed_amount' => (float) $totalPerformedAmount,
                'planned_advance_amount' => (float) $contract->planned_advance_amount,
                'actual_advance_amount' => (float) $contract->actual_advance_amount,
                'remaining_advance_amount' => (float) $contract->remaining_advance_amount,
                'advance_payment_percentage' => (float) $contract->advance_payment_percentage,
                'is_advance_fully_paid' => $contract->is_advance_fully_paid,
            ],
            'status' => [
                'current_status' => $contract->status->value,
                'is_nearing_limit' => $totalPerformedAmount >= ($aggregatedContractAmount * 0.9),
                'can_add_work' => ! in_array($contract->status->value, ['completed', 'terminated']),
                'is_overdue' => $contract->is_overdue,
                'days_until_deadline' => $contract->end_date ? now()->diffInDays($contract->end_date, false) : null,
            ],
            'counts' => [
                'total_works' => $contract->completedWorks->count(),
                'confirmed_works' => $confirmedWorks->count(),
                'pending_works' => $pendingWorks->count(),
                'performance_acts' => $contract->performanceActs->count(),
                'approved_acts' => $approvedActs->count(),
                'payments_count' => $paymentsCount,
                'child_contracts' => $contract->childContracts->count(),
                'agreements_count' => $contract->agreements->count(),
                'specifications_count' => $contract->specifications->count(),
            ],
        ];
    }

    /**
     * Рассчитать фактическую сумму выполненных работ по актам
     */
    private function calculateActualPerformedAmount($approvedActs): float
    {
        $totalAmount = 0;

        foreach ($approvedActs as $act) {
            // Если у акта есть связанные работы - считаем по ним
            if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                $totalAmount += $act->completedWorks->sum('pivot.included_amount');
            } else {
                // Если работы не связаны - используем старое поле amount (для совместимости)
                $totalAmount += $act->amount ?? 0;
            }
        }

        return $totalAmount;
    }

    /**
     * Построить статистику работ из загруженных данных
     */
    private function buildWorksStatisticsFromLoaded(Contract $contract): array
    {
        $works = $contract->completedWorks;

        $pending = $works->where('status', 'pending');
        $confirmed = $works->where('status', 'confirmed');
        $rejected = $works->where('status', 'rejected');

        return [
            'pending' => [
                'count' => $pending->count(),
                'amount' => (float) $pending->sum('total_amount'),
                'avg_amount' => $pending->count() > 0 ? (float) $pending->avg('total_amount') : 0,
            ],
            'confirmed' => [
                'count' => $confirmed->count(),
                'amount' => (float) $confirmed->sum('total_amount'),
                'avg_amount' => $confirmed->count() > 0 ? (float) $confirmed->avg('total_amount') : 0,
            ],
            'rejected' => [
                'count' => $rejected->count(),
                'amount' => (float) $rejected->sum('total_amount'),
                'avg_amount' => $rejected->count() > 0 ? (float) $rejected->avg('total_amount') : 0,
            ],
        ];
    }

    /**
     * Форматировать загруженные выполненные работы
     */
    private function formatCompletedWorksFromLoaded(Contract $contract): array
    {
        return $contract->completedWorks->map(function ($work) {
            return [
                'id' => $work->id,
                'work_type_name' => $work->workType->name ?? 'Не указано',
                'user_name' => $work->user->name ?? 'Не указано',
                'quantity' => (float) $work->quantity,
                'total_amount' => (float) $work->total_amount,
                'status' => $work->status,
                'completion_date' => $work->completion_date,
                'materials_count' => $work->materials->count() ?? 0,
                'materials_amount' => (float) ($work->materials->sum('pivot.total_amount') ?? 0),
            ];
        })->toArray();
    }

    public function attachToParentContract(int $contractId, int $organizationId, int $parentContractId): Contract
    {
        $this->logging->business('contract.parent.attach.started', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
            'parent_contract_id' => $parentContractId,
        ]);

        DB::beginTransaction();
        try {
            $contract = $this->contractRepository->findAccessible($contractId, $organizationId);

            if (! $contract) {
                throw new Exception('Контракт не найден');
            }

            $parentContract = $this->contractRepository->findAccessible($parentContractId, $organizationId);

            if (! $parentContract) {
                throw new Exception('Родительский контракт не найден');
            }

            if ($contract->parent_contract_id === $parentContractId) {
                throw new Exception('Контракт уже привязан к этому родительскому контракту');
            }

            $contract->parent_contract_id = $parentContractId;
            $contract->save();

            DB::commit();

            $this->logging->business('contract.parent.attach.success', [
                'contract_id' => $contractId,
                'parent_contract_id' => $parentContractId,
            ]);

            return $contract->fresh(['contractor', 'project', 'parentContract']);

        } catch (Exception $e) {
            DB::rollBack();

            $this->logging->technical('contract.parent.attach.failed', [
                'contract_id' => $contractId,
                'parent_contract_id' => $parentContractId,
                'error' => $e->getMessage(),
            ], 'error');

            throw $e;
        }
    }

    public function detachFromParentContract(int $contractId, int $organizationId): Contract
    {
        $this->logging->business('contract.parent.detach.started', [
            'contract_id' => $contractId,
            'organization_id' => $organizationId,
        ]);

        DB::beginTransaction();
        try {
            $contract = $this->contractRepository->findAccessible($contractId, $organizationId);

            if (! $contract) {
                throw new Exception('Контракт не найден');
            }

            if (! $contract->parent_contract_id) {
                throw new Exception('Контракт не привязан к родительскому контракту');
            }

            $oldParentId = $contract->parent_contract_id;
            $contract->parent_contract_id = null;
            $contract->save();

            DB::commit();

            $this->logging->business('contract.parent.detach.success', [
                'contract_id' => $contractId,
                'old_parent_contract_id' => $oldParentId,
            ]);

            return $contract->fresh(['contractor', 'project', 'parentContract']);

        } catch (Exception $e) {
            DB::rollBack();

            $this->logging->technical('contract.parent.detach.failed', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
            ], 'error');

            throw $e;
        }
    }

    public function getContractsSummary(int $organizationId, array $filters = []): array
    {
        $query = Contract::query();
        $relatedPartyOrganizationId = isset($filters['related_party_organization_id'])
            ? (int) $filters['related_party_organization_id']
            : null;

        $applyVisibilityScope = static function ($queryBuilder) use ($organizationId, $relatedPartyOrganizationId, $filters) {
            if ($relatedPartyOrganizationId) {
                return $queryBuilder->where(function ($scopedQuery) use ($organizationId, $relatedPartyOrganizationId) {
                    $scopedQuery->where('contracts.organization_id', $organizationId)
                        ->orWhereHas('contractor', function ($contractorQuery) use ($relatedPartyOrganizationId) {
                            $contractorQuery->where('source_organization_id', $relatedPartyOrganizationId);
                        });
                });
            }

            if (empty($filters['contractor_context'])) {
                return $queryBuilder->where('contracts.organization_id', $organizationId);
            }

            return $queryBuilder;
        };

        $applyTableVisibilityScope = static function ($queryBuilder, string $contractsAlias = 'contracts') use ($organizationId, $relatedPartyOrganizationId, $filters) {
            if ($relatedPartyOrganizationId) {
                return $queryBuilder->where(function ($scopedQuery) use ($organizationId, $relatedPartyOrganizationId, $contractsAlias) {
                    $scopedQuery->where($contractsAlias.'.organization_id', $organizationId)
                        ->orWhereExists(function ($subQuery) use ($relatedPartyOrganizationId, $contractsAlias) {
                            $subQuery->select(DB::raw(1))
                                ->from('contractors')
                                ->whereColumn('contractors.id', $contractsAlias.'.contractor_id')
                                ->where('contractors.source_organization_id', $relatedPartyOrganizationId);
                        });
                });
            }

            if (empty($filters['contractor_context'])) {
                return $queryBuilder->where($contractsAlias.'.organization_id', $organizationId);
            }

            return $queryBuilder;
        };

        $applyVisibilityScope($query);

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['contractor_id'])) {
            $query->where('contractor_id', $filters['contractor_id']);
        }

        if (! empty($filters['contract_side_type'])) {
            $query->where('contract_side_type', $filters['contract_side_type']);
        }

        if (array_key_exists('requires_contract_side_review', $filters) && $filters['requires_contract_side_review'] !== null) {
            $query->where('requires_contract_side_review', (bool) $filters['requires_contract_side_review']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['work_type_category'])) {
            $query->where('work_type_category', $filters['work_type_category']);
        }

        $statusCounts = (clone $query)->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $financialData = (clone $query)->select(
            DB::raw('SUM(CASE 
                WHEN is_fixed_amount = true THEN COALESCE(base_amount, 0)
                ELSE COALESCE(total_amount, 0)
            END) as base_sum'),
            DB::raw('SUM(CASE 
                WHEN is_fixed_amount = true THEN COALESCE(base_amount, 0)
                ELSE COALESCE(total_amount, 0)
            END) as total_with_gp'),
            DB::raw('SUM(COALESCE(planned_advance_amount, 0)) as total_planned_advance'),
            DB::raw('SUM(COALESCE(actual_advance_amount, 0)) as total_actual_advance')
        )->first();

        $totalContracts = (clone $query)->count();
        $activeContracts = $statusCounts['active'] ?? 0;
        $completedContracts = $statusCounts['completed'] ?? 0;
        $cancelledContracts = $statusCounts['cancelled'] ?? 0;

        $baseSum = $financialData->base_sum ?? 0;
        $totalAmountWithGp = $financialData->total_with_gp ?? 0;
        $totalPlannedAdvance = $financialData->total_planned_advance ?? 0;
        $totalActualAdvance = $financialData->total_actual_advance ?? 0;

        $performedAmountQuery = DB::table('contract_performance_acts')
            ->join('contracts', 'contract_performance_acts.contract_id', '=', 'contracts.id')
            ->whereNull('contracts.deleted_at')
            ->where('contract_performance_acts.is_approved', true)
            // Фильтруем акты по project_id напрямую для корректной работы с мультипроектными контрактами
            ->when(! empty($filters['project_id']), fn ($q) => $q->where('contract_performance_acts.project_id', $filters['project_id']))
            ->when(! empty($filters['contractor_id']), fn ($q) => $q->where('contracts.contractor_id', $filters['contractor_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('contracts.status', $filters['status']))
            ->when(! empty($filters['work_type_category']), fn ($q) => $q->where('contracts.work_type_category', $filters['work_type_category']));
        $applyTableVisibilityScope($performedAmountQuery);
        $totalPerformedAmount = (float) ($performedAmountQuery->sum('contract_performance_acts.amount') ?: 0);

        // Используем таблицу payment_documents вместо устаревшей invoices
        $paidAmountQuery = DB::table('payment_documents')
            ->join('contracts', function ($join) {
                $join->on('payment_documents.invoiceable_id', '=', 'contracts.id')
                    ->where('payment_documents.invoiceable_type', '=', 'App\\Models\\Contract');
            })
            ->whereNull('contracts.deleted_at')
            ->whereNull('payment_documents.deleted_at')
            ->when(! empty($filters['project_id']), fn ($q) => $q->where('contracts.project_id', $filters['project_id']))
            ->when(! empty($filters['contractor_id']), fn ($q) => $q->where('contracts.contractor_id', $filters['contractor_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('contracts.status', $filters['status']))
            ->when(! empty($filters['work_type_category']), fn ($q) => $q->where('contracts.work_type_category', $filters['work_type_category']));
        $applyTableVisibilityScope($paidAmountQuery);
        $totalPaidAmount = (float) ($paidAmountQuery->sum('payment_documents.paid_amount') ?: 0);

        $overdueContracts = (clone $query)
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<', now())
            ->count();

        $nearingLimitSubquery = DB::table('contracts as c')
            ->leftJoin('completed_works as cw', function ($join) {
                $join->on('cw.contract_id', '=', 'c.id')
                    ->where('cw.status', '=', 'confirmed');
            })
            ->select('c.id', 'c.total_amount', DB::raw('COALESCE(SUM(cw.total_amount), 0) as completed_amount'))
            ->whereNull('c.deleted_at')
            ->when(! empty($filters['project_id']), fn ($q) => $q->where('c.project_id', $filters['project_id']))
            ->when(! empty($filters['contractor_id']), fn ($q) => $q->where('c.contractor_id', $filters['contractor_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('c.status', $filters['status']))
            ->when(! empty($filters['work_type_category']), fn ($q) => $q->where('c.work_type_category', $filters['work_type_category']))
            ->groupBy('c.id', 'c.total_amount')
            ->havingRaw('(c.total_amount - COALESCE(SUM(cw.total_amount), 0)) <= (c.total_amount * 0.1)')
            ->havingRaw('(c.total_amount - COALESCE(SUM(cw.total_amount), 0)) > 0');
        $applyTableVisibilityScope($nearingLimitSubquery, 'c');

        $nearingLimitContracts = DB::table(DB::raw("({$nearingLimitSubquery->toSql()}) as subquery"))
            ->mergeBindings($nearingLimitSubquery)
            ->count();

        $reviewContracts = (clone $query)
            ->where('requires_contract_side_review', true)
            ->count();

        return [
            'total_contracts' => $totalContracts,
            'by_status' => [
                'active' => $activeContracts,
                'completed' => $completedContracts,
                'cancelled' => $cancelledContracts,
            ],
            'financial' => [
                'total_amount' => round($baseSum, 2),
                'total_amount_with_gp' => round($totalAmountWithGp, 2),
                'total_performed_amount' => round($totalPerformedAmount, 2),
                'total_paid_amount' => round($totalPaidAmount, 2),
                'remaining_to_perform' => round($totalAmountWithGp - $totalPerformedAmount, 2),
                'remaining_to_pay' => round($totalPerformedAmount - $totalPaidAmount, 2),
                'performance_percentage' => $totalAmountWithGp > 0 ? round(($totalPerformedAmount / $totalAmountWithGp) * 100, 2) : 0,
                'payment_percentage' => $totalPerformedAmount > 0 ? round(($totalPaidAmount / $totalPerformedAmount) * 100, 2) : 0,
            ],
            'advances' => [
                'total_planned' => round($totalPlannedAdvance, 2),
                'total_actual' => round($totalActualAdvance, 2),
                'remaining' => round($totalPlannedAdvance - $totalActualAdvance, 2),
            ],
            'alerts' => [
                'overdue_contracts' => $overdueContracts,
                'nearing_limit_contracts' => $nearingLimitContracts,
                'contract_side_review_count' => $reviewContracts,
            ],
        ];
    }
}
