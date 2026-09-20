<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\CompletedWork;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

final class CompletedWorkActEligibilityService
{
    public function query(int $contractId, string $periodStart, string $periodEnd): Builder
    {
        $contract = Contract::query()->findOrFail($contractId);
        $projectIds = $contract->getProjectIds();

        return CompletedWork::query()
            ->where('organization_id', $contract->organization_id)
            ->whereNull('deleted_at')
            ->where('status', CompletedWork::STATUS_CONFIRMED)
            ->where('quantity', '>=', 0)
            ->where(function (Builder $query): void {
                $query->whereNull('completed_quantity')->orWhereColumn('quantity', 'completed_quantity');
            })
            ->whereBetween('completion_date', [$periodStart, $periodEnd])
            ->whereIn('work_origin_type', [
                CompletedWork::ORIGIN_MANUAL,
                CompletedWork::ORIGIN_SCHEDULE,
                CompletedWork::ORIGIN_JOURNAL,
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('journal_material_id')
                    ->whereNull('journal_equipment_id')
                    ->whereNull('journal_worker_id')
                    ->where(function (Builder $factQuery): void {
                        $factQuery
                            ->whereNull('additional_info')
                            ->orWhereRaw("additional_info->>'fact_kind' IS NULL")
                            ->orWhereRaw(
                                "additional_info->>'fact_kind' NOT IN (?, ?, ?, ?)",
                                ['material', 'equipment', 'labor', 'worker'],
                            );
                    });
            })
            ->when($projectIds !== [], static fn (Builder $query): Builder => $query->whereIn('project_id', $projectIds))
            ->where(function (Builder $query) use ($contractId): void {
                $query
                    ->where('contract_id', $contractId)
                    ->orWhere(function (Builder $fallbackQuery) use ($contractId): void {
                        $fallbackQuery
                            ->whereNull('contract_id')
                            ->whereHas('estimateItem.contractLinks', static function (Builder $linkQuery) use ($contractId): void {
                                $linkQuery->where('contract_id', $contractId);
                            });
                    });
            });
    }
}
