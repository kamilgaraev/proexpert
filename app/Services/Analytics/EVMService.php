<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EVMService
{
    private const CACHE_TTL = 600; // 10 minutes
    private const CACHE_PREFIX = 'project_metrics:';

    /**
     * Calculate EVM metrics for a project with caching
     *
     * @param Project|int $project Project model or project ID
     * @param int|null $visibleOrganizationId Organization scope for contractor-visible metrics
     * @return array
     */
    public function calculateMetrics(Project|int $project, ?int $visibleOrganizationId = null): array
    {
        $projectId = $project instanceof Project ? $project->id : $project;
        
        $cacheKey = self::CACHE_PREFIX . $projectId;

        if ($visibleOrganizationId === null) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('EVM metrics cache hit', ['project_id' => $projectId]);
                return $cached;
            }
        }

        // Load project if ID was passed
        if (!($project instanceof Project)) {
            $project = Project::find($projectId);
            if (!$project) {
                throw new \InvalidArgumentException("Project {$projectId} not found");
            }
        }

        Log::debug('EVM metrics cache miss, calculating', ['project_id' => $projectId]);

        // Calculate metrics
        $metrics = $this->calculateMetricsInternal($project, $visibleOrganizationId);

        if ($visibleOrganizationId === null) {
            Cache::put($cacheKey, $metrics, self::CACHE_TTL);
        }

        return $metrics;
    }

    /**
     * Internal method to calculate metrics
     */
    private function calculateMetricsInternal(Project $project, ?int $visibleOrganizationId = null): array
    {
        // 1. Basic Data Points
        $bac = $this->calculateBAC($project, $visibleOrganizationId);
        $pv = $this->calculatePV($project, $bac);
        $ev = $this->calculateEV($project, $visibleOrganizationId);
        $ac = $this->calculateAC($project, $visibleOrganizationId);

        // 2. Variances
        $sv = $ev - $pv; // Schedule Variance
        $cv = $ev - $ac; // Cost Variance

        // 3. Performance Indices
        // Avoid division by zero
        $spi = $pv > 0 ? round($ev / $pv, 2) : 1.0; // Schedule Performance Index
        $cpi = $ac > 0 ? round($ev / $ac, 2) : 1.0; // Cost Performance Index

        // 4. Forecasts
        // EAC = BAC / CPI (Estimate At Completion)
        $eac = $cpi > 0 ? $bac / $cpi : $bac;
        
        // VAC = BAC - EAC (Variance At Completion)
        $vac = $bac - $eac;

        // TCPI = (BAC - EV) / (BAC - AC) (To-Complete Performance Index)
        $remainingBudget = $bac - $ac;
        $remainingWork = $bac - $ev;
        $tcpi = $remainingBudget > 0 ? round($remainingWork / $remainingBudget, 2) : 0.0;

        // 5. Health status
        $health = $this->calculateHealth($spi, $cpi);

        return [
            'bac' => $bac,
            'pv' => $pv,
            'ev' => $ev,
            'ac' => $ac,
            'sv' => $sv,
            'cv' => $cv,
            'spi' => $spi,
            'cpi' => $cpi,
            'eac' => $eac,
            'vac' => $vac,
            'tcpi' => $tcpi,
            'health' => $health,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Calculate project health status
     */
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

    /**
     * Invalidate cache for a project
     */
    public function invalidateCache(int $projectId): void
    {
        $cacheKey = self::CACHE_PREFIX . $projectId;
        Cache::forget($cacheKey);
        Log::debug('EVM metrics cache invalidated', ['project_id' => $projectId]);
    }

    /**
     * Batch calculate metrics for multiple projects
     */
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

    /**
     * Calculate Planned Value (PV)
     * Linear distribution based on project start/end dates
     * 
     * @param Project $project
     * @return float
     */
    private function calculatePV(Project $project, float $bac): float
    {
        $start = $project->start_date;
        $end = $project->end_date;
        
        if (!$start || !$end) {
            // If dates are missing, fallback to 0 or full BAC depending on state?
            // Let's assume 0 if no schedule
            return 0.0;
        }

        $now = Carbon::now();
        
        // If project hasn't started, PV is 0
        if ($now < $start) {
            return 0.0;
        }

        // If project is past end date, PV is full BAC
        if ($now > $end) {
            return $bac;
        }

        $totalDuration = $start->diffInDays($end) + 1; // +1 to include both start and end dates
        $daysPassed = $start->diffInDays($now) + 1;

        if ($totalDuration <= 0) {
            return $bac;
        }

        // Linear proportion
        return round($bac * ($daysPassed / $totalDuration), 2);
    }

    /**
     * Calculate Earned Value (EV)
     * Sum of approved performance acts (документально подтвержденные работы)
     * 
     * @param Project $project
     * @return float
     */
    private function calculateEV(Project $project, ?int $visibleOrganizationId = null): float
    {
        $contractIds = $this->getProjectContractIds($project, $visibleOrganizationId);
        
        if ($contractIds->isEmpty()) {
            return 0.0;
        }

        // Получаем все утвержденные акты с загруженными работами
        $approvedActs = ContractPerformanceAct::whereIn('contract_id', $contractIds)
            ->where('is_approved', true)
            ->with('completedWorks') // Загружаем связанные работы
            ->get();

        $totalAmount = 0;
        
        foreach ($approvedActs as $act) {
            // Если у акта есть связанные работы - считаем по included_amount из промежуточной таблицы
            if ($act->completedWorks->count() > 0) {
                $totalAmount += $act->completedWorks->sum('pivot.included_amount');
            } else {
                // Если работы не связаны - используем поле amount (для совместимости со старыми актами)
                $totalAmount += $act->amount ?? 0;
            }
        }
        
        return (float) $totalAmount;
    }

    /**
     * Calculate Actual Cost (AC)
     * Sum of payments made (using new payment_documents structure)
     * 
     * @param Project $project
     * @return float
     */
    private function calculateAC(Project $project, ?int $visibleOrganizationId = null): float
    {
        // AC is tracked via payment_documents (new payment system)
        // Payment documents are linked to contracts via polymorphic relation
        
        $contractIds = $this->getProjectContractIds($project, $visibleOrganizationId);
        
        if ($contractIds->isEmpty()) {
            return 0.0;
        }

        // Sum paid_amount from payment_documents where invoiceable_type = Contract
        return (float) DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->whereIn('invoiceable_id', $contractIds)
            ->whereNull('deleted_at')
            ->sum('paid_amount');
    }

    private function calculateBAC(Project $project, ?int $visibleOrganizationId = null): float
    {
        if ($visibleOrganizationId === null) {
            return (float) $project->budget_amount;
        }

        return (float) $this->projectContractsQuery($project, $visibleOrganizationId)
            ->selectRaw('SUM(CASE WHEN is_fixed_amount = true THEN COALESCE(base_amount, total_amount, 0) ELSE COALESCE(total_amount, 0) END) as bac')
            ->value('bac');
    }

    private function getProjectContractIds(Project $project, ?int $visibleOrganizationId = null): Collection
    {
        return $this->projectContractsQuery($project, $visibleOrganizationId)->pluck('contracts.id');
    }

    private function projectContractsQuery(Project $project, ?int $visibleOrganizationId = null)
    {
        return Contract::query()
            ->whereNull('contracts.deleted_at')
            ->where(function ($query) use ($project) {
                $query->where('contracts.project_id', $project->id)
                    ->orWhereExists(function ($subQuery) use ($project) {
                        $subQuery->select(DB::raw(1))
                            ->from('contract_project')
                            ->whereColumn('contract_project.contract_id', 'contracts.id')
                            ->where('contract_project.project_id', $project->id);
                    });
            })
            ->when($visibleOrganizationId !== null, function ($query) use ($visibleOrganizationId) {
                $query->whereHas('contractor', function ($contractorQuery) use ($visibleOrganizationId) {
                    $contractorQuery->where('source_organization_id', $visibleOrganizationId);
                });
            });
    }
}

