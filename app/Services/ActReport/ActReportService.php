<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use App\Exceptions\BusinessLogicException;
use App\Services\Contract\ContractAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ActReportService
{
    public function __construct(
        private readonly ActReportWorkflowService $workflowService,
        private readonly ContractAccessService $contractAccessService,
    ) {
    }

    /**
     * Получить список актов с фильтрацией и пагинацией
     */
    public function getActsList(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->buildActsQuery($organizationId, $filters);

        if (array_key_exists('project_ids', $filters)) {
            $projectIds = array_map('intval', (array) $filters['project_ids']);
            $includeProjectless = (bool) ($filters['include_projectless'] ?? false);
            $query->where(function (Builder $projectScope) use ($projectIds, $includeProjectless): void {
                if ($projectIds !== []) {
                    $projectScope->whereIn('contract_performance_acts.project_id', $projectIds)
                        ->orWhere(function (Builder $contractProjectScope) use ($projectIds): void {
                            $contractProjectScope->whereNull('contract_performance_acts.project_id')
                                ->whereHas('contract', static fn (Builder $contract): Builder => $contract->whereIn('project_id', $projectIds));
                        });
                }

                if ($includeProjectless) {
                    $projectlessScope = static function (Builder $projectless): void {
                        $projectless->whereNull('contract_performance_acts.project_id')
                            ->whereDoesntHave('contract', static fn (Builder $contract): Builder => $contract->whereNotNull('project_id'));
                    };
                    if ($projectIds === []) {
                        $projectScope->where($projectlessScope);
                    } else {
                        $projectScope->orWhere($projectlessScope);
                    }
                } elseif ($projectIds === []) {
                    $projectScope->whereRaw('1 = 0');
                }
            });
        }

        // Применяем фильтры

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'act_date';
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc'));

        $allowedSortFields = ['act_date', 'act_document_number', 'amount', 'created_at'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'act_date';
        }

        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    public function getActsSummary(int $organizationId, array $filters = []): array
    {
        $query = $this->buildActsQuery($organizationId, $filters);

        return [
            'total_acts' => (clone $query)->count(),
            'approved_acts' => (clone $query)->where('is_approved', true)->count(),
            'pending_acts' => (clone $query)->where('status', ContractPerformanceAct::STATUS_PENDING_APPROVAL)->count(),
            'total_amount' => (float) (clone $query)->sum('amount'),
        ];
    }

    private function buildActsQuery(int $organizationId, array $filters = []): Builder
    {
        $query = ContractPerformanceAct::with([
            'contract.project',
            'contract.contractor',
            'completedWorks',
            'lines',
            'files',
        ])->whereHas('contract', function (Builder $q) use ($organizationId): void {
            $this->contractAccessService->applyAccessibleScope($q, $organizationId);
        });

        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Создать новый акт
     */
    public function createAct(int $organizationId, array $data): ContractPerformanceAct
    {
        throw new BusinessLogicException(trans_message('act_reports.use_acting_wizard'), 422);
    }

    /**
     * Обновить акт
     */
    public function updateAct(ContractPerformanceAct $act, array $data): ContractPerformanceAct
    {
        if ($act->is_approved) {
            throw new BusinessLogicException(trans_message('act_reports.act_already_approved'), 400);
        }

        $this->workflowService->assertMutable($act);

        DB::beginTransaction();
        try {
            $act->update([
                'act_document_number' => $data['act_document_number'] ?? $act->act_document_number,
                'act_date' => $data['act_date'] ?? $act->act_date,
                'description' => $data['description'] ?? $act->description,
            ]);

            DB::commit();

            Log::info('[ActReportService] Act updated', [
                'act_id' => $act->id,
            ]);

            return $act->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ActReportService] Failed to update act', [
                'error' => $e->getMessage(),
                'act_id' => $act->id,
            ]);
            throw new BusinessLogicException(
                trans_message('act_reports.update_failed'),
                500,
                $e
            );
        }
    }

    /**
     * Применить фильтры к запросу
     */
    protected function applyFilters($query, array $filters): void
    {
        if (!empty($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }

        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (!empty($filters['contractor_id'])) {
            $query->whereHas('contract', function ($q) use ($filters) {
                $q->where('contractor_id', $filters['contractor_id']);
            });
        }

        if (array_key_exists('is_approved', $filters)) {
            $isApproved = $this->normalizeBooleanFilter($filters['is_approved']);

            if ($isApproved !== null) {
                $query->where('is_approved', $isApproved);
            }
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('act_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('act_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('act_document_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('contract', function ($contractQuery) use ($search) {
                      $contractQuery->where('number', 'like', "%{$search}%");
                  });
            });
        }
    }

    /**
     * Валидация контракта
     */
    protected function validateContract(int $organizationId, int $contractId): Contract
    {
        $contract = $this->contractAccessService->findAccessible($contractId, $organizationId);

        if (!$contract) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_found'), 404);
        }

        return $contract;
    }

    private function normalizeBooleanFilter(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }
}
