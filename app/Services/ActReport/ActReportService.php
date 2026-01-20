<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ActReportService
{
    /**
     * Получить список актов с фильтрацией и пагинацией
     */
    public function getActsList(int $organizationId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ContractPerformanceAct::with([
            'contract.project',
            'contract.contractor',
            'completedWorks'
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
        // Проверяем контракт
        $contract = $this->validateContract($organizationId, $data['contract_id']);

        DB::beginTransaction();
        try {
            // Создаём акт
            $act = ContractPerformanceAct::create([
                'contract_id' => $contract->id,
                'project_id' => $contract->project_id,
                'act_document_number' => $data['act_document_number'],
                'act_date' => $data['act_date'],
                'description' => $data['description'] ?? null,
                'amount' => 0,
                'is_approved' => false,
            ]);

            // Прикрепляем работы если переданы
            if (!empty($data['work_ids'])) {
                $this->attachWorksToAct($act, $data['work_ids']);
            }

            DB::commit();

            // Загружаем связи
            $act->load([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.user',
                'completedWorks.materials'
            ]);

            Log::info('[ActReportService] Act created', [
                'act_id' => $act->id,
                'contract_id' => $contract->id,
                'organization_id' => $organizationId,
            ]);

            return $act;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ActReportService] Failed to create act', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);
            throw new BusinessLogicException(
                trans_message('act_reports.create_failed'),
                500,
                $e
            );
        }
    }

    /**
     * Обновить акт
     */
    public function updateAct(ContractPerformanceAct $act, array $data): ContractPerformanceAct
    {
        if ($act->is_approved) {
            throw new BusinessLogicException(trans_message('act_reports.act_already_approved'), 400);
        }

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
        // Получаем ID работ, которые уже в акте
        $existingWorkIds = $act->completedWorks()->pluck('completed_works.id')->toArray();

        // Получаем все подтверждённые работы контракта, которых нет в акте
        return CompletedWork::where('contract_id', $act->contract_id)
            ->where('status', 'confirmed')
            ->whereNotIn('id', $existingWorkIds)
            ->with(['workType', 'user', 'materials'])
            ->orderBy('work_date', 'desc')
            ->get();
    }

    /**
     * Прикрепить работы к акту
     */
    public function attachWorksToAct(ContractPerformanceAct $act, array $workIds): void
    {
        if ($act->is_approved) {
            throw new BusinessLogicException(trans_message('act_reports.act_already_approved'), 400);
        }

        // Получаем валидные работы
        $validWorks = CompletedWork::whereIn('id', $workIds)
            ->where('contract_id', $act->contract_id)
            ->where('status', 'confirmed')
            ->get();

        if ($validWorks->isEmpty()) {
            throw new BusinessLogicException(trans_message('act_reports.works_not_confirmed'), 400);
        }

        $pivotData = [];
        $totalAmount = 0;

        foreach ($validWorks as $work) {
            $pivotData[$work->id] = [
                'included_quantity' => $work->quantity,
                'included_amount' => $work->total_amount,
                'notes' => null,
            ];
            $totalAmount += $work->total_amount;
        }

        $act->completedWorks()->attach($pivotData);
        $act->increment('amount', $totalAmount);

        Log::info('[ActReportService] Works attached to act', [
            'act_id' => $act->id,
            'works_count' => count($validWorks),
            'total_amount' => $totalAmount,
        ]);
    }

    /**
     * Обновить работы в акте
     */
    public function updateWorksInAct(ContractPerformanceAct $act, array $updates): void
    {
        if ($act->is_approved) {
            throw new BusinessLogicException(trans_message('act_reports.act_already_approved'), 400);
        }

        DB::beginTransaction();
        try {
            $newTotalAmount = 0;

            foreach ($updates as $update) {
                $workId = $update['work_id'];
                $includedQuantity = $update['included_quantity'];
                $includedAmount = $update['included_amount'];
                $notes = $update['notes'] ?? null;

                $act->completedWorks()->updateExistingPivot($workId, [
                    'included_quantity' => $includedQuantity,
                    'included_amount' => $includedAmount,
                    'notes' => $notes,
                ]);

                $newTotalAmount += $includedAmount;
            }

            $act->update(['amount' => $newTotalAmount]);

            DB::commit();

            Log::info('[ActReportService] Works updated in act', [
                'act_id' => $act->id,
                'new_total_amount' => $newTotalAmount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
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

        if (isset($filters['is_approved'])) {
            $query->where('is_approved', (bool)$filters['is_approved']);
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
