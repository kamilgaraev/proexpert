<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class ExpenseBreakdownWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::EXPENSE_BREAKDOWN;
    }

    public function validateRequest(WidgetDataRequest $request): bool
    {
        return $request->hasDateRange();
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $materialCosts = $this->getMaterialCosts($request);
        $laborCosts = $this->getLaborCosts($request);
        $contractorCosts = $this->getContractorCosts($request);
        $totalExpenses = $materialCosts + $laborCosts + $contractorCosts;

        $byProject = $this->getExpensesByProject($request);
        $byCategory = $this->getExpensesByCategory($request);

        return [
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'total_expenses' => $totalExpenses,
            'breakdown' => [
                [
                    'category' => 'Материалы',
                    'amount' => $materialCosts,
                    'percentage' => $totalExpenses > 0 ? round(($materialCosts / $totalExpenses) * 100, 2) : 0,
                ],
                [
                    'category' => 'Труд',
                    'amount' => $laborCosts,
                    'percentage' => $totalExpenses > 0 ? round(($laborCosts / $totalExpenses) * 100, 2) : 0,
                ],
                [
                    'category' => 'Подрядчики',
                    'amount' => $contractorCosts,
                    'percentage' => $totalExpenses > 0 ? round(($contractorCosts / $totalExpenses) * 100, 2) : 0,
                ],
            ],
            'by_project' => $byProject,
            'by_category' => $byCategory,
        ];
    }

    protected function getMaterialCosts(WidgetDataRequest $request): float
    {
        if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            return 0.0;
        }

        $result = DB::table('completed_work_materials')
            ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->when($request->projectId, fn($q) => $q->where('completed_works.project_id', $request->projectId))
            ->sum('completed_work_materials.total_amount');

        return $result ? (float)$result : 0.0;
    }

    protected function getLaborCosts(WidgetDataRequest $request): float
    {
        $result = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->when($request->projectId, fn($q) => $q->where('completed_works.project_id', $request->projectId))
            ->sum(DB::raw('completed_works.quantity * completed_works.price * 0.3'));

        return $result ? (float)$result : 0.0;
    }

    protected function getContractorCosts(WidgetDataRequest $request): float
    {
        $result = DB::table('material_receipts')
            ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('material_receipts.receipt_date', [$request->from, $request->to])
            ->whereIn('material_receipts.status', ['confirmed'])
            ->when($request->projectId, fn($q) => $q->where('material_receipts.project_id', $request->projectId))
            ->sum('material_receipts.total_amount');

        return $result ? (float)$result : 0.0;
    }

    protected function getExpensesByProject(WidgetDataRequest $request): array
    {
        $projects = DB::table('projects')
            ->where('organization_id', $request->organizationId)
            ->whereBetween('created_at', [$request->from, $request->to])
            ->select('id', 'name')
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

            $materials = $this->getMaterialCosts($projectRequest);
            $labor = $this->getLaborCosts($projectRequest);
            $contractors = $this->getContractorCosts($projectRequest);
            $total = $materials + $labor + $contractors;

            if ($total > 0) {
                $results[] = [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'total' => $total,
                    'materials' => $materials,
                    'labor' => $labor,
                    'contractors' => $contractors,
                ];
            }
        }

        usort($results, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_slice($results, 0, 10);
    }

    protected function getExpensesByCategory(WidgetDataRequest $request): array
    {
        if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            return [];
        }

        $results = DB::table('completed_work_materials')
            ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->join('materials', 'completed_work_materials.material_id', '=', 'materials.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->when($request->projectId, fn($q) => $q->where('completed_works.project_id', $request->projectId))
            ->select(
                DB::raw('COALESCE(materials.category, "Прочее") as category'),
                DB::raw('SUM(completed_work_materials.total_amount) as total')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return $results->map(function($item) {
            return [
                'category' => $item->category,
                'amount' => (float)$item->total,
            ];
        })->toArray();
    }
}

