<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ActReportService
{
    public function __construct(
        private readonly ActReportWorkflowService $workflowService
    ) {
    }

    /**
     * Получить список актов с фильтрацией и пагинацией
     */
    public function getActsList(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'completedWorks',
                'lines',
                'files',
            ])->whereHas('contract', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        });

        // Применяем фильтры
        $this->applyFilters($query, $filters);

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'act_date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        
        $allowedSortFields = ['act_date', 'act_document_number', 'amount', 'created_at'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'act_date';
        }

        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
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
     * Получить доступные работы для добавления в акт
     */
    public function getAvailableWorks(ContractPerformanceAct $act): Collection
    {
        $existingWorkIds = $act->completedWorks()->pluck('completed_works.id')->toArray();
        $actedQuantities = PerformanceActLine::query()
            ->where('line_type', PerformanceActLine::TYPE_COMPLETED_WORK)
            ->whereNot('performance_act_id', $act->id)
            ->whereNotNull('completed_work_id')
            ->selectRaw('completed_work_id, SUM(quantity) as acted_quantity')
            ->groupBy('completed_work_id')
            ->pluck('acted_quantity', 'completed_work_id');

        return CompletedWork::where('contract_id', $act->contract_id)
            ->where('status', 'confirmed')
            ->where('work_origin_type', CompletedWork::ORIGIN_JOURNAL)
            ->whereNotNull('journal_entry_id')
            ->whereNotIn('id', $existingWorkIds)
            ->with(['workType', 'user'])
            ->orderBy('completion_date', 'desc')
            ->get()
            ->filter(function (CompletedWork $work) use ($actedQuantities): bool {
                $effectiveQuantity = (float) ($work->completed_quantity ?? $work->quantity);
                $actedQuantity = (float) ($actedQuantities[$work->id] ?? 0);

                return $effectiveQuantity > $actedQuantity;
            })
            ->values();
    }

    /**
     * Прикрепить работы к акту
     */
    public function attachWorksToAct(ContractPerformanceAct $act, array $workIds): void
    {
        throw new BusinessLogicException(trans_message('act_reports.use_acting_wizard'), 422);
    }

    /**
     * Обновить работы в акте
     */
    public function updateWorksInAct(ContractPerformanceAct $act, array $updates): void
    {
        throw new BusinessLogicException(trans_message('act_reports.use_acting_wizard'), 422);
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

        if (isset($filters['is_approved'])) {
            $query->where('is_approved', (bool)$filters['is_approved']);
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
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$contract) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_found'), 404);
        }

        return $contract;
    }
}
