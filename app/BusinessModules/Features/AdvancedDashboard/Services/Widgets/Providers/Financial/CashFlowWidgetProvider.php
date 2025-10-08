<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CashFlowWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CASH_FLOW;
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
        
        $inflow = $this->calculateInflow($request);
        $outflow = $this->calculateOutflow($request);
        $monthlyData = $this->getMonthlyBreakdown($request);

        return [
            'period' => [
                'from' => $request->from->toIso8601String(),
                'to' => $request->to->toIso8601String(),
            ],
            'total_inflow' => (float)$inflow,
            'total_outflow' => (float)$outflow,
            'net_cash_flow' => (float)($inflow - $outflow),
            'monthly_breakdown' => $monthlyData,
            'inflow_by_category' => $this->getInflowByCategory($request),
            'outflow_by_category' => $this->getOutflowByCategory($request),
        ];
    }

    protected function calculateInflow(WidgetDataRequest $request): float
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

    protected function calculateOutflow(WidgetDataRequest $request): float
    {
        try {
            $materialCosts = 0.0;
            if (DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
                $materialCostsQuery = DB::table('completed_work_materials')
                    ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                    ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                    ->where('projects.organization_id', $request->organizationId)
                    ->whereBetween('completed_works.created_at', [$request->from, $request->to]);

                if ($request->projectId) {
                    $materialCostsQuery->where('completed_works.project_id', $request->projectId);
                }

                $result = $materialCostsQuery->sum('completed_work_materials.total_amount');
                $materialCosts = $result ? (float)$result : 0.0;
            }

            $laborCostsQuery = DB::table('completed_works')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $request->organizationId)
                ->whereBetween('completed_works.created_at', [$request->from, $request->to]);

            if ($request->projectId) {
                $laborCostsQuery->where('completed_works.project_id', $request->projectId);
            }

            $laborResult = $laborCostsQuery->sum(DB::raw('completed_works.quantity * completed_works.price * 0.3'));
            $laborCosts = $laborResult ? (float)$laborResult : 0.0;

            $materialReceiptsQuery = DB::table('material_receipts')
                ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $request->organizationId)
                ->whereBetween('material_receipts.receipt_date', [$request->from, $request->to])
                ->whereIn('material_receipts.status', ['confirmed']);

            if ($request->projectId) {
                $materialReceiptsQuery->where('material_receipts.project_id', $request->projectId);
            }

            $materialReceiptsResult = $materialReceiptsQuery->sum('material_receipts.total_amount');
            $contractorCosts = $materialReceiptsResult ? (float)$materialReceiptsResult : 0.0;

            return $materialCosts + $laborCosts + $contractorCosts;
        } catch (\Exception $e) {
            Log::warning('Error calculating outflow', [
                'error' => $e->getMessage(),
                'organization_id' => $request->organizationId,
            ]);
            return 0.0;
        }
    }

    protected function getMonthlyBreakdown(WidgetDataRequest $request): array
    {
        $monthlyData = [];
        $current = $request->from->copy()->startOfMonth();
        $end = $request->to->copy()->endOfMonth();

        while ($current->lte($end)) {
            $monthStart = $current->copy()->startOfMonth();
            $monthEnd = $current->copy()->endOfMonth();

            $monthRequest = new WidgetDataRequest(
                widgetType: $request->widgetType,
                organizationId: $request->organizationId,
                userId: $request->userId,
                from: $monthStart,
                to: $monthEnd,
                projectId: $request->projectId,
            );

            $inflow = $this->calculateInflow($monthRequest);
            $outflow = $this->calculateOutflow($monthRequest);

            $monthlyData[] = [
                'month' => $current->format('Y-m'),
                'month_name' => $current->format('F Y'),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'net' => $inflow - $outflow,
            ];

            $current->addMonth();
        }

        return $monthlyData;
    }

    protected function getInflowByCategory(WidgetDataRequest $request): array
    {
        $contractsInflow = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->whereBetween('created_at', [$request->from, $request->to])
            ->where('status', 'active')
            ->when($request->projectId, fn($q) => $q->where('project_id', $request->projectId))
            ->sum('total_amount');

        $advancePayments = DB::table('contracts')
            ->where('organization_id', $request->organizationId)
            ->whereBetween('created_at', [$request->from, $request->to])
            ->when($request->projectId, fn($q) => $q->where('project_id', $request->projectId))
            ->sum('actual_advance_amount');

        $completedWorksPayments = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('completed_works.created_at', [$request->from, $request->to])
            ->when($request->projectId, fn($q) => $q->where('completed_works.project_id', $request->projectId))
            ->sum(DB::raw('completed_works.quantity * completed_works.price'));

        $total = (float)($contractsInflow + $advancePayments + $completedWorksPayments);

        return [
            [
                'category' => 'Контракты',
                'amount' => (float)$contractsInflow,
                'percentage' => $total > 0 ? round(($contractsInflow / $total) * 100, 2) : 0,
            ],
            [
                'category' => 'Авансовые платежи',
                'amount' => (float)$advancePayments,
                'percentage' => $total > 0 ? round(($advancePayments / $total) * 100, 2) : 0,
            ],
            [
                'category' => 'Оплата выполненных работ',
                'amount' => (float)$completedWorksPayments,
                'percentage' => $total > 0 ? round(($completedWorksPayments / $total) * 100, 2) : 0,
            ],
        ];
    }

    protected function getOutflowByCategory(WidgetDataRequest $request): array
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

        $total = $materialCosts + $laborCosts + $contractorCosts;

        return [
            [
                'category' => 'Материалы',
                'amount' => $materialCosts,
                'percentage' => $total > 0 ? round(($materialCosts / $total) * 100, 2) : 0,
            ],
            [
                'category' => 'Трудовые затраты',
                'amount' => $laborCosts,
                'percentage' => $total > 0 ? round(($laborCosts / $total) * 100, 2) : 0,
            ],
            [
                'category' => 'Подрядчики',
                'amount' => $contractorCosts,
                'percentage' => $total > 0 ? round(($contractorCosts / $total) * 100, 2) : 0,
            ],
        ];
    }
}

