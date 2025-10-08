<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProfitLossWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::PROFIT_LOSS;
    }

    public function validateRequest(WidgetDataRequest $request): bool
    {
        return true;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        if (!$request->from || !$request->to) {
            $request = new WidgetDataRequest(
                widgetType: $request->widgetType,
                organizationId: $request->organizationId,
                userId: $request->userId,
                from: now()->startOfMonth(),
                to: now()->endOfMonth(),
                projectId: $request->projectId,
                contractId: $request->contractId,
                employeeId: $request->employeeId,
                filters: $request->filters,
                options: $request->options,
            );
        }

        $revenue = $this->calculateRevenue($request);
        $cogs = $this->calculateCOGS($request);
        $opex = $this->calculateOpEx($request);
        
        $grossProfit = $revenue - $cogs;
        $operatingProfit = $grossProfit - $opex;
        $netProfit = $operatingProfit;

        return [
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'revenue' => $revenue,
            'cost_of_goods_sold' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_profit_margin' => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0,
            'operating_expenses' => $opex,
            'operating_profit' => $operatingProfit,
            'operating_profit_margin' => $revenue > 0 ? round(($operatingProfit / $revenue) * 100, 2) : 0,
            'net_profit' => $netProfit,
            'net_profit_margin' => $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0,
            'by_project' => $this->getProfitByProject($request),
        ];
    }

    protected function calculateRevenue(WidgetDataRequest $request): float
    {
        $query = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->whereBetween('created_at', [$request->from, $request->to])
            ->where('status', 'active');

        if ($request->projectId) {
            $query->where('project_id', $request->projectId);
        }

        $result = $query->sum('total_amount');
        return $result ? (float)$result : 0.0;
    }

    protected function calculateCOGS(WidgetDataRequest $request): float
    {
        $outflow = $this->calculateOutflow($request);
        return $outflow * 0.7;
    }

    protected function calculateOpEx(WidgetDataRequest $request): float
    {
        $outflow = $this->calculateOutflow($request);
        return $outflow * 0.3;
    }

    protected function calculateOutflow(WidgetDataRequest $request): float
    {
        $materialCosts = 0.0;
        if (DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            $result = DB::table('completed_work_materials')
                ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $request->organizationId)
                ->whereBetween('completed_works.created_at', [$request->from, $request->to])
                ->when($request->projectId, fn($q) => $q->where('completed_works.project_id', $request->projectId))
                ->sum('completed_work_materials.total_amount');
            $materialCosts = $result ? (float)$result : 0.0;
        }

        $laborCosts = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->when($request->projectId, fn($q) => $q->where('completed_works.project_id', $request->projectId))
            ->sum(DB::raw('completed_works.quantity * completed_works.price * 0.3'));
        $laborCosts = $laborCosts ? (float)$laborCosts : 0.0;

        $contractorCosts = DB::table('material_receipts')
            ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('material_receipts.receipt_date', [$request->from, $request->to])
            ->whereIn('material_receipts.status', ['confirmed'])
            ->when($request->projectId, fn($q) => $q->where('material_receipts.project_id', $request->projectId))
            ->sum('material_receipts.total_amount');
        $contractorCosts = $contractorCosts ? (float)$contractorCosts : 0.0;

        return $materialCosts + $laborCosts + $contractorCosts;
    }

    protected function getProfitByProject(WidgetDataRequest $request): array
    {
        $projects = Project::where('organization_id', $request->organizationId)
            ->whereBetween('created_at', [$request->from, $request->to])
            ->get();

        $results = [];

        foreach ($projects as $project) {
            $projectRequest = new WidgetDataRequest(
                widgetType: $request->widgetType,
                organizationId: $request->organizationId,
                userId: $request->userId,
                from: $request->from,
                to: $request->to,
                projectId: $project->id,
            );

            $revenue = $this->calculateRevenue($projectRequest);
            $cogs = $this->calculateCOGS($projectRequest);
            $profit = $revenue - $cogs;

            $results[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        }

        usort($results, fn($a, $b) => $b['profit'] <=> $a['profit']);

        return $results;
    }
}

