<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ROIWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::ROI;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->startOfYear();
        $to = $request->to ?? Carbon::now();

        if ($request->projectId) {
            return $this->calculateProjectROI($request->projectId, $from, $to);
        }

        $projects = Project::where('organization_id', $request->organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $roiData = [];
        $totalInvestment = 0;
        $totalProfit = 0;

        foreach ($projects as $project) {
            $projectROI = $this->calculateProjectROI($project->id, $from, $to);
            $roiData[] = array_merge($projectROI, [
                'project_id' => $project->id,
                'project_name' => $project->name,
            ]);

            $totalInvestment += $projectROI['investment'];
            $totalProfit += $projectROI['profit'];
        }

        usort($roiData, fn($a, $b) => $b['roi_percentage'] <=> $a['roi_percentage']);

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'total_investment' => $totalInvestment,
            'total_profit' => $totalProfit,
            'total_roi_percentage' => $totalInvestment > 0
                ? round(($totalProfit / $totalInvestment) * 100, 2)
                : 0,
            'projects_count' => count($roiData),
            'projects' => $roiData,
            'top_performers' => array_slice($roiData, 0, 5),
            'worst_performers' => array_slice(array_reverse($roiData), 0, 5),
        ];
    }

    protected function calculateProjectROI(int $projectId, Carbon $from, Carbon $to): array
    {
        $project = Project::find($projectId);

        if (!$project) {
            return [
                'investment' => 0,
                'revenue' => 0,
                'profit' => 0,
                'roi_percentage' => 0,
            ];
        }

        $investment = $this->calculateOutflow($project->organization_id, $from, $to, $projectId);
        $revenue = $this->calculateRevenue($project->organization_id, $from, $to, $projectId);
        $profit = $revenue - $investment;
        $roi = $investment > 0 ? round(($profit / $investment) * 100, 2) : 0;

        return [
            'investment' => $investment,
            'revenue' => $revenue,
            'profit' => $profit,
            'roi_percentage' => $roi,
        ];
    }

    protected function calculateRevenue(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        $query = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'active');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $result = $query->sum('total_amount');
        return $result ? (float)$result : 0.0;
    }

    protected function calculateOutflow(int $organizationId, Carbon $from, Carbon $to, ?int $projectId): float
    {
        $materialCosts = 0.0;
        if (DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            $result = DB::table('completed_work_materials')
                ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereBetween('completed_works.created_at', [$from, $to])
                ->when($projectId, fn($q) => $q->where('completed_works.project_id', $projectId))
                ->sum('completed_work_materials.total_amount');
            $materialCosts = $result ? (float)$result : 0.0;
        }

        $laborCosts = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->when($projectId, fn($q) => $q->where('completed_works.project_id', $projectId))
            ->sum(DB::raw('completed_works.quantity * completed_works.price * 0.3'));
        $laborCosts = $laborCosts ? (float)$laborCosts : 0.0;

        $contractorCosts = DB::table('material_receipts')
            ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('material_receipts.receipt_date', [$from, $to])
            ->whereIn('material_receipts.status', ['confirmed'])
            ->when($projectId, fn($q) => $q->where('material_receipts.project_id', $projectId))
            ->sum('material_receipts.total_amount');
        $contractorCosts = $contractorCosts ? (float)$contractorCosts : 0.0;

        return $materialCosts + $laborCosts + $contractorCosts;
    }
}

