<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\ContractPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EVMService
{
    /**
     * Calculate EVM metrics for a project
     *
     * @param Project $project
     * @return array
     */
    public function calculateMetrics(Project $project): array
    {
        // 1. Basic Data Points
        $bac = (float) $project->budget_amount;
        $pv = $this->calculatePV($project);
        $ev = $this->calculateEV($project);
        $ac = $this->calculateAC($project);

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
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Calculate Planned Value (PV)
     * Linear distribution based on project start/end dates
     * 
     * @param Project $project
     * @return float
     */
    private function calculatePV(Project $project): float
    {
        $bac = (float) $project->budget_amount;
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
     * Sum of confirmed completed works
     * 
     * @param Project $project
     * @return float
     */
    private function calculateEV(Project $project): float
    {
        return (float) CompletedWork::where('project_id', $project->id)
            ->where('status', 'confirmed') // Only confirmed works count as Earned
            ->sum('total_amount');
    }

    /**
     * Calculate Actual Cost (AC)
     * Sum of payments made
     * 
     * @param Project $project
     * @return float
     */
    private function calculateAC(Project $project): float
    {
        // AC is usually tracked via payments or actual invoices
        // We link payments to contracts, and contracts to projects
        
        // Get contract IDs for this project
        $contractIds = Contract::where('project_id', $project->id)->pluck('id');
        
        if ($contractIds->isEmpty()) {
            return 0.0;
        }

        return (float) ContractPayment::whereIn('contract_id', $contractIds)
            ->sum('amount');
    }
}

