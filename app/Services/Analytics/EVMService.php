<?php

namespace App\Services\Analytics;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EVMService
{
    private const CACHE_TTL = 600;

    private const CACHE_PREFIX = 'project_metrics:v2:';

    public function calculateMetrics(Project|int $project, ?int $visibleOrganizationId = null): array
    {
        $projectId = $project instanceof Project ? $project->id : $project;
        $cacheKey = self::CACHE_PREFIX.$projectId;

        if ($visibleOrganizationId === null) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('EVM metrics cache hit', ['project_id' => $projectId]);

                return $cached;
            }
        }

        if (! ($project instanceof Project)) {
            $project = Project::find($projectId);
            if (! $project) {
                throw new \InvalidArgumentException("Project {$projectId} not found");
            }
        }

        Log::debug('EVM metrics cache miss, calculating', ['project_id' => $projectId]);

        $metrics = $this->calculateMetricsInternal($project, $visibleOrganizationId);

        if ($visibleOrganizationId === null) {
            Cache::put($cacheKey, $metrics, self::CACHE_TTL);
        }

        return $metrics;
    }

    private function calculateMetricsInternal(Project $project, ?int $visibleOrganizationId = null): array
    {
        $asOf = Carbon::now();
        $bac = $this->calculateBAC($project, $visibleOrganizationId);
        $pv = $this->calculatePV($project, $bac, $visibleOrganizationId, $asOf);
        $ev = $this->calculateEV($project, $visibleOrganizationId, $asOf);
        $ac = $this->calculateAC($project, $visibleOrganizationId, $asOf);

        $sv = $ev - $pv;
        $cv = $ev - $ac;
        $rawSpi = $pv > 0 ? $ev / $pv : 1.0;
        $rawCpi = $ac > 0 ? $ev / $ac : 1.0;
        $spi = round($rawSpi, 2);
        $cpi = round($rawCpi, 2);
        $eac = $rawCpi > 0 ? $bac / $rawCpi : $bac;
        $vac = $bac - $eac;
        $remainingBudget = $bac - $ac;
        $remainingWork = $bac - $ev;
        $tcpi = $remainingBudget > 0 ? round($remainingWork / $remainingBudget, 2) : 0.0;

        return [
            'bac' => round($bac, 2),
            'pv' => round($pv, 2),
            'ev' => round($ev, 2),
            'ac' => round($ac, 2),
            'sv' => round($sv, 2),
            'cv' => round($cv, 2),
            'spi' => $spi,
            'cpi' => $cpi,
            'eac' => round($eac, 2),
            'vac' => round($vac, 2),
            'tcpi' => $tcpi,
            'health' => $this->calculateHealth($spi, $cpi),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function calculateHealth(float $spi, float $cpi): string
    {
        if ($spi < 0.8 || $cpi < 0.8) {
            return 'critical';
        }

        if ($spi < 0.95 || $cpi < 0.95) {
            return 'warning';
        }

        return 'good';
    }

    public function invalidateCache(int $projectId): void
    {
        Cache::forget(self::CACHE_PREFIX.$projectId);
        Log::debug('EVM metrics cache invalidated', ['project_id' => $projectId]);
    }

    public function invalidateCacheForContract(Contract $contract): void
    {
        $this->invalidateProjectIds($this->projectIdsForContract($contract));
    }

    public function invalidateCacheForPerformanceAct(
        ContractPerformanceAct $act,
        bool $includeOriginal = false
    ): void {
        $this->invalidateProjectIds($this->projectIdsForPerformanceAct($act, $includeOriginal));
    }

    public function invalidateCacheForPaymentDocument(
        PaymentDocument $document,
        bool $includeOriginal = false
    ): void {
        $projectIds = collect([$document->project_id]);

        foreach (['invoiceable', 'source'] as $relationPrefix) {
            $projectIds = $projectIds->merge($this->projectIdsForPaymentRelation(
                $document->getAttribute("{$relationPrefix}_type"),
                $document->getAttribute("{$relationPrefix}_id")
            ));

            if ($includeOriginal) {
                $projectIds = $projectIds->merge($this->projectIdsForPaymentRelation(
                    $document->getOriginal("{$relationPrefix}_type"),
                    $document->getOriginal("{$relationPrefix}_id")
                ));
            }
        }

        if ($includeOriginal) {
            $projectIds->push($document->getOriginal('project_id'));
        }

        $this->invalidateProjectIds($projectIds);
    }

    public function batchCalculateMetrics(array $projectIds): array
    {
        $results = [];

        foreach ($projectIds as $projectId) {
            try {
                $results[$projectId] = $this->calculateMetrics($projectId);
            } catch (\Exception $e) {
                Log::error('Failed to calculate EVM metrics for project', [
                    'project_id' => $projectId,
                    'error' => $e->getMessage(),
                ]);
                $results[$projectId] = null;
            }
        }

        return $results;
    }

    private function calculatePV(
        Project $project,
        float $bac,
        ?int $visibleOrganizationId,
        Carbon $asOf
    ): float {
        if ($bac <= 0) {
            return 0.0;
        }

        if ($visibleOrganizationId === null) {
            $scheduleValue = $this->calculateSchedulePlannedValue($project, $bac, $asOf);

            if ($scheduleValue !== null) {
                return $scheduleValue;
            }
        }

        return $this->calculateLinearPV($project, $bac, $asOf);
    }

    private function calculateLinearPV(Project $project, float $bac, Carbon $asOf): float
    {
        $start = $project->start_date;
        $end = $project->end_date;

        if (! $start || ! $end) {
            return 0.0;
        }

        if ($asOf < $start) {
            return 0.0;
        }

        if ($asOf > $end) {
            return $bac;
        }

        $totalDuration = (int) $start->diffInDays($end) + 1;
        $daysPassed = (int) $start->diffInDays($asOf) + 1;

        if ($totalDuration <= 0) {
            return $bac;
        }

        return round($bac * ($daysPassed / $totalDuration), 2);
    }

    private function calculateSchedulePlannedValue(Project $project, float $bac, Carbon $asOf): ?float
    {
        $schedule = DB::table('project_schedules')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->first(['id']);

        if (! $schedule) {
            return null;
        }

        $tasks = DB::table('schedule_tasks')
            ->where('schedule_id', $schedule->id)
            ->whereNull('deleted_at')
            ->whereNotIn('task_type', ['summary', 'container'])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('estimated_cost')
            ->where('estimated_cost', '>', 0)
            ->get([
                'estimated_cost',
                'baseline_start_date',
                'baseline_end_date',
                'planned_start_date',
                'planned_end_date',
            ]);

        if ($tasks->isEmpty()) {
            return null;
        }

        $scheduleBac = (float) $tasks->sum(fn ($task): float => (float) $task->estimated_cost);

        if ($scheduleBac <= 0) {
            return null;
        }

        $plannedValue = 0.0;

        foreach ($tasks as $task) {
            $plannedValue += $this->calculateTimePhasedValue(
                (float) $task->estimated_cost,
                $task->baseline_start_date ?: $task->planned_start_date,
                $task->baseline_end_date ?: $task->planned_end_date,
                $asOf
            );
        }

        return round(min($bac, $plannedValue * ($bac / $scheduleBac)), 2);
    }

    private function calculateTimePhasedValue(float $amount, mixed $startValue, mixed $endValue, Carbon $asOf): float
    {
        if ($amount <= 0 || ! $startValue || ! $endValue) {
            return 0.0;
        }

        $start = Carbon::parse($startValue)->startOfDay();
        $end = Carbon::parse($endValue)->startOfDay();
        $asOfDay = $asOf->copy()->startOfDay();

        if ($asOfDay->lt($start)) {
            return 0.0;
        }

        if ($asOfDay->gte($end)) {
            return $amount;
        }

        $totalDuration = max(1, (int) $start->diffInDays($end) + 1);
        $daysPassed = max(0, min($totalDuration, (int) $start->diffInDays($asOfDay) + 1));

        return $amount * ($daysPassed / $totalDuration);
    }

    private function calculateEV(Project $project, ?int $visibleOrganizationId = null, ?Carbon $asOf = null): float
    {
        $contractIds = $this->getProjectContractIds($project, $visibleOrganizationId);

        if ($contractIds->isEmpty()) {
            return 0.0;
        }

        $lineTotals = DB::table('performance_act_lines')
            ->select('performance_act_id')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('performance_act_id');

        $workTotals = DB::table('performance_act_completed_works')
            ->select('performance_act_id')
            ->selectRaw('SUM(included_amount) as total')
            ->groupBy('performance_act_id');

        $query = DB::table('contract_performance_acts as cpa')
            ->join('contracts', 'contracts.id', '=', 'cpa.contract_id')
            ->leftJoinSub($lineTotals, 'act_line_totals', 'act_line_totals.performance_act_id', '=', 'cpa.id')
            ->leftJoinSub($workTotals, 'act_work_totals', 'act_work_totals.performance_act_id', '=', 'cpa.id')
            ->whereIn('cpa.contract_id', $contractIds)
            ->where('cpa.is_approved', true);

        $this->scopeActsToProject($query, $project);

        if ($asOf !== null) {
            $this->scopeActsToDate($query, $asOf);
        }

        return (float) $query
            ->selectRaw('SUM(COALESCE(NULLIF(act_line_totals.total, 0), NULLIF(act_work_totals.total, 0), cpa.amount, 0)) as total')
            ->value('total');
    }

    private function calculateAC(Project $project, ?int $visibleOrganizationId = null, ?Carbon $asOf = null): float
    {
        $contractIds = $this->getProjectContractIds($project, $visibleOrganizationId);

        if ($contractIds->isEmpty()) {
            return 0.0;
        }

        $query = DB::table('payment_documents as pd')
            ->whereNull('pd.deleted_at')
            ->where(function ($query): void {
                $query->whereNull('pd.status')
                    ->orWhereNotIn('pd.status', ['cancelled', 'rejected']);
            })
            ->where('pd.paid_amount', '>', 0)
            ->where(function ($query) use ($project, $contractIds): void {
                $this->scopePaymentDocumentsToContract($query, 'invoiceable', $project, $contractIds, 'and');
                $this->scopePaymentDocumentsToAct($query, 'invoiceable', $project, $contractIds);
                $this->scopePaymentDocumentsToContract($query, 'source', $project, $contractIds);
                $this->scopePaymentDocumentsToAct($query, 'source', $project, $contractIds);
            });

        if ($asOf !== null) {
            $query->whereRaw('COALESCE(pd.paid_at, pd.document_date, pd.created_at) <= ?', [
                $asOf->copy()->endOfDay()->toDateTimeString(),
            ]);
        }

        return (float) $query->sum('pd.paid_amount');
    }

    private function calculateBAC(Project $project, ?int $visibleOrganizationId = null): float
    {
        $projectBudget = (float) $project->budget_amount;

        if ($visibleOrganizationId === null && $projectBudget > 0) {
            return $projectBudget;
        }

        $contractBac = (float) $this->projectContractsQuery($project, $visibleOrganizationId)
            ->leftJoin('contract_project_allocations as cpa', function ($join) use ($project): void {
                $join->on('cpa.contract_id', '=', 'contracts.id')
                    ->where('cpa.project_id', '=', $project->id)
                    ->where('cpa.is_active', true)
                    ->whereNull('cpa.deleted_at');
            })
            ->leftJoinSub(
                DB::table('contract_project')
                    ->select('contract_id')
                    ->selectRaw('COUNT(*) as projects_count')
                    ->groupBy('contract_id'),
                'contract_project_counts',
                'contract_project_counts.contract_id',
                '=',
                'contracts.id'
            )
            ->selectRaw("
                SUM(
                    CASE
                        WHEN cpa.allocation_type = 'fixed' AND cpa.allocated_amount IS NOT NULL
                            THEN cpa.allocated_amount
                        WHEN cpa.allocation_type = 'percentage' AND cpa.allocated_percentage IS NOT NULL
                            THEN COALESCE(contracts.base_amount, contracts.total_amount, 0) * cpa.allocated_percentage / 100
                        WHEN contracts.is_multi_project = true AND COALESCE(contract_project_counts.projects_count, 0) > 0
                            THEN COALESCE(contracts.base_amount, contracts.total_amount, 0) / contract_project_counts.projects_count
                        WHEN contracts.is_fixed_amount = true
                            THEN COALESCE(contracts.base_amount, contracts.total_amount, 0)
                        ELSE COALESCE(contracts.total_amount, 0)
                    END
                ) as bac
            ")
            ->value('bac');

        if ($contractBac > 0) {
            return $contractBac;
        }

        $scheduleBac = $this->calculateScheduleBAC($project);

        if ($scheduleBac > 0) {
            return $scheduleBac;
        }

        return $projectBudget;
    }

    private function invalidateProjectIds(Collection $projectIds): void
    {
        foreach ($this->normalizeProjectIds($projectIds) as $projectId) {
            $this->invalidateCache($projectId);
        }
    }

    private function projectIdsForPaymentRelation(mixed $type, mixed $id): Collection
    {
        if (! $type || ! $id) {
            return collect();
        }

        $relationType = ltrim((string) $type, '\\');
        $relationId = (int) $id;

        if ($relationId <= 0) {
            return collect();
        }

        if ($relationType === Contract::class) {
            $contract = Contract::query()->with('projects:id')->find($relationId);

            return $contract ? $this->projectIdsForContract($contract) : collect();
        }

        if ($relationType === ContractPerformanceAct::class) {
            $act = ContractPerformanceAct::query()
                ->with(['contract.projects:id'])
                ->find($relationId);

            return $act ? $this->projectIdsForPerformanceAct($act) : collect();
        }

        return collect();
    }

    private function projectIdsForPerformanceAct(
        ContractPerformanceAct $act,
        bool $includeOriginal = false
    ): Collection {
        $projectIds = collect([$act->project_id]);

        $contract = $act->relationLoaded('contract')
            ? $act->contract
            : $act->contract()->with('projects:id')->first();

        if ($contract) {
            $projectIds = $projectIds->merge($this->projectIdsForContract($contract));
        }

        if ($includeOriginal) {
            $projectIds->push($act->getOriginal('project_id'));

            $originalContractId = $act->getOriginal('contract_id');

            if ($originalContractId && (int) $originalContractId !== (int) $act->contract_id) {
                $originalContract = Contract::query()->with('projects:id')->find((int) $originalContractId);

                if ($originalContract) {
                    $projectIds = $projectIds->merge($this->projectIdsForContract($originalContract));
                }
            }
        }

        return $this->normalizeProjectIds($projectIds);
    }

    private function projectIdsForContract(Contract $contract): Collection
    {
        $projectIds = collect([$contract->project_id]);

        if ($contract->relationLoaded('projects')) {
            $projectIds = $projectIds->merge($contract->projects->pluck('id'));
        } else {
            $projectIds = $projectIds->merge($contract->projects()->pluck('projects.id'));
        }

        return $this->normalizeProjectIds($projectIds);
    }

    private function normalizeProjectIds(Collection $projectIds): Collection
    {
        return $projectIds
            ->filter(fn (mixed $projectId): bool => $projectId !== null && (int) $projectId > 0)
            ->map(fn (mixed $projectId): int => (int) $projectId)
            ->unique()
            ->values();
    }

    private function calculateScheduleBAC(Project $project): float
    {
        $schedule = DB::table('project_schedules')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->first(['id', 'total_estimated_cost']);

        if (! $schedule) {
            return 0.0;
        }

        $tasksAmount = (float) DB::table('schedule_tasks')
            ->where('schedule_id', $schedule->id)
            ->whereNull('deleted_at')
            ->whereNotIn('task_type', ['summary', 'container'])
            ->where('status', '!=', 'cancelled')
            ->sum('estimated_cost');

        return $tasksAmount > 0 ? $tasksAmount : (float) ($schedule->total_estimated_cost ?? 0);
    }

    private function getProjectContractIds(Project $project, ?int $visibleOrganizationId = null): Collection
    {
        return $this->projectContractsQuery($project, $visibleOrganizationId)->pluck('contracts.id');
    }

    private function projectContractsQuery(Project $project, ?int $visibleOrganizationId = null)
    {
        return Contract::query()
            ->whereNull('contracts.deleted_at')
            ->where(function ($query) use ($project): void {
                $query->where('contracts.project_id', $project->id)
                    ->orWhereExists(function ($subQuery) use ($project): void {
                        $subQuery->select(DB::raw(1))
                            ->from('contract_project')
                            ->whereColumn('contract_project.contract_id', 'contracts.id')
                            ->where('contract_project.project_id', $project->id);
                    });
            })
            ->when($visibleOrganizationId !== null, function ($query) use ($visibleOrganizationId): void {
                $query->whereHas('contractor', function ($contractorQuery) use ($visibleOrganizationId): void {
                    $contractorQuery->where('source_organization_id', $visibleOrganizationId);
                });
            });
    }

    private function scopeActsToProject($query, Project $project): void
    {
        $query->where(function ($query) use ($project): void {
            $query->where('cpa.project_id', $project->id)
                ->orWhere(function ($query) use ($project): void {
                    $query->whereNull('cpa.project_id')
                        ->where('contracts.project_id', $project->id)
                        ->where(function ($query): void {
                            $query->where('contracts.is_multi_project', false)
                                ->orWhereNull('contracts.is_multi_project');
                        });
                });
        });
    }

    private function scopeActsToDate($query, Carbon $asOf): void
    {
        $query->whereRaw('COALESCE(cpa.approval_date, cpa.act_date, cpa.created_at) <= ?', [
            $asOf->copy()->endOfDay()->toDateTimeString(),
        ]);
    }

    private function scopePaymentDocumentsToContract(
        $query,
        string $relationPrefix,
        Project $project,
        Collection $contractIds,
        string $boolean = 'or'
    ): void {
        $typeColumn = "pd.{$relationPrefix}_type";
        $idColumn = "pd.{$relationPrefix}_id";
        $method = $boolean === 'and' ? 'where' : 'orWhere';

        $query->{$method}(function ($query) use ($typeColumn, $idColumn, $project, $contractIds): void {
            $query->where($typeColumn, Contract::class)
                ->whereIn($idColumn, $contractIds)
                ->where(function ($query) use ($idColumn, $project): void {
                    $query->where('pd.project_id', $project->id)
                        ->orWhere(function ($query) use ($idColumn, $project): void {
                            $query->whereNull('pd.project_id')
                                ->whereExists(function ($query) use ($idColumn, $project): void {
                                    $query->select(DB::raw(1))
                                        ->from('contracts as payment_contracts')
                                        ->whereColumn('payment_contracts.id', $idColumn)
                                        ->where('payment_contracts.project_id', $project->id)
                                        ->where(function ($query): void {
                                            $query->where('payment_contracts.is_multi_project', false)
                                                ->orWhereNull('payment_contracts.is_multi_project');
                                        });
                                });
                        });
                });
        });
    }

    private function scopePaymentDocumentsToAct(
        $query,
        string $relationPrefix,
        Project $project,
        Collection $contractIds,
        string $boolean = 'or'
    ): void {
        $typeColumn = "pd.{$relationPrefix}_type";
        $idColumn = "pd.{$relationPrefix}_id";
        $method = $boolean === 'and' ? 'where' : 'orWhere';

        $query->{$method}(function ($query) use ($typeColumn, $idColumn, $project, $contractIds): void {
            $query->where($typeColumn, ContractPerformanceAct::class)
                ->whereExists(function ($query) use ($idColumn, $project, $contractIds): void {
                    $query->select(DB::raw(1))
                        ->from('contract_performance_acts as payment_acts')
                        ->join('contracts as payment_act_contracts', 'payment_act_contracts.id', '=', 'payment_acts.contract_id')
                        ->whereColumn('payment_acts.id', $idColumn)
                        ->whereIn('payment_acts.contract_id', $contractIds)
                        ->where(function ($query) use ($project): void {
                            $query->where('payment_acts.project_id', $project->id)
                                ->orWhere(function ($query) use ($project): void {
                                    $query->whereNull('payment_acts.project_id')
                                        ->where('payment_act_contracts.project_id', $project->id)
                                        ->where(function ($query): void {
                                            $query->where('payment_act_contracts.is_multi_project', false)
                                                ->orWhereNull('payment_act_contracts.is_multi_project');
                                        });
                                });
                        });
                });
        });
    }
}
