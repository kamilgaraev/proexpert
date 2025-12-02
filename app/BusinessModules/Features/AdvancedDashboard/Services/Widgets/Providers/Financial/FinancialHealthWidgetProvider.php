<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FinancialHealthWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::FINANCIAL_HEALTH;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonths(3);
        $to = $request->to ?? Carbon::now();

        $revenue = $this->getRevenue($request->organizationId, $from, $to);
        $expenses = $this->getExpenses($request->organizationId, $from, $to);
        $netIncome = $revenue - $expenses;
        
        $totalContracts = $this->getTotalContractsValue($request->organizationId);
        $completedWork = $this->getCompletedWorkValue($request->organizationId, $from, $to);
        $completionRate = $totalContracts > 0 ? round(($completedWork / $totalContracts) * 100, 2) : 0;

        $receivables = $this->getReceivablesTotal($request->organizationId);
        $payables = $this->getPayablesTotal($request->organizationId);
        $workingCapital = $receivables - $payables;

        $activeProjects = $this->getActiveProjectsCount($request->organizationId);
        $activeContracts = $this->getActiveContractsCount($request->organizationId);

        $profitMargin = $revenue > 0 ? round(($netIncome / $revenue) * 100, 2) : 0;
        
        $healthScore = $this->calculateHealthScore([
            'profit_margin' => $profitMargin,
            'completion_rate' => $completionRate,
            'working_capital' => $workingCapital,
            'revenue' => $revenue,
        ]);

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_income' => $netIncome,
            'profit_margin' => $profitMargin,
            'contracts_value' => $totalContracts,
            'completion_rate' => $completionRate,
            'receivables' => $receivables,
            'payables' => $payables,
            'working_capital' => $workingCapital,
            'active_projects' => $activeProjects,
            'active_contracts' => $activeContracts,
            'health_score' => $healthScore,
            'health_status' => $this->getHealthStatus($healthScore),
        ];
    }

    protected function getRevenue(int $organizationId, Carbon $from, Carbon $to): float
    {
        $result = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'active')
            ->sum('total_amount');

        return $result ? (float)$result : 0.0;
    }

    protected function getExpenses(int $organizationId, Carbon $from, Carbon $to): float
    {
        $materialCosts = 0.0;
        if (DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
            $result = DB::table('completed_work_materials')
                ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->whereBetween('completed_works.created_at', [$from, $to])
                ->sum('completed_work_materials.total_amount');
            $materialCosts = $result ? (float)$result : 0.0;
        }

        $laborCosts = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->sum(DB::raw('completed_works.quantity * completed_works.price * 0.3'));
        $laborCosts = $laborCosts ? (float)$laborCosts : 0.0;

        $contractorCosts = DB::table('material_receipts')
            ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('material_receipts.receipt_date', [$from, $to])
            ->whereIn('material_receipts.status', ['confirmed'])
            ->sum('material_receipts.total_amount');
        $contractorCosts = $contractorCosts ? (float)$contractorCosts : 0.0;

        return $materialCosts + $laborCosts + $contractorCosts;
    }

    protected function getTotalContractsValue(int $organizationId): float
    {
        $result = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->sum('total_amount');

        return $result ? (float)$result : 0.0;
    }

    protected function getCompletedWorkValue(int $organizationId, Carbon $from, Carbon $to): float
    {
        $result = DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereBetween('completed_works.created_at', [$from, $to])
            ->sum(DB::raw('completed_works.quantity * completed_works.price'));

        return $result ? (float)$result : 0.0;
    }

    protected function getReceivablesTotal(int $organizationId): float
    {
        $contracts = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->pluck('id');

        if ($contracts->isEmpty()) {
            return 0.0;
        }

        $completedAmount = DB::table('completed_works')
            ->whereIn('contract_id', $contracts)
            ->sum(DB::raw('quantity * price'));

        // Используем новую таблицу invoices для расчета оплаченной суммы
        $paidAmount = DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\Models\\Contract')
            ->whereIn('invoiceable_id', $contracts)
            ->whereNull('deleted_at')
            ->sum('paid_amount');

        return (float)($completedAmount - $paidAmount);
    }

    protected function getPayablesTotal(int $organizationId): float
    {
        $result = DB::table('material_receipts')
            ->join('projects', 'material_receipts.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereIn('material_receipts.status', ['confirmed'])
            ->sum('material_receipts.total_amount');

        return $result ? (float)$result : 0.0;
    }

    protected function getActiveProjectsCount(int $organizationId): int
    {
        return DB::table('projects')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();
    }

    protected function getActiveContractsCount(int $organizationId): int
    {
        return DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['active', 'in_progress'])
            ->count();
    }

    protected function calculateHealthScore(array $metrics): float
    {
        $profitMarginScore = $this->normalizeProfitMargin($metrics['profit_margin']);
        $completionScore = $metrics['completion_rate'] / 100;
        $workingCapitalScore = $this->normalizeWorkingCapital($metrics['working_capital']);
        $revenueScore = $this->normalizeRevenue($metrics['revenue']);

        $totalScore = (
            $profitMarginScore * 0.4 +
            $completionScore * 0.3 +
            $workingCapitalScore * 0.2 +
            $revenueScore * 0.1
        ) * 100;

        return round(min(100, max(0, $totalScore)), 2);
    }

    protected function normalizeProfitMargin(float $margin): float
    {
        if ($margin < 0) return 0;
        if ($margin > 30) return 1;
        return $margin / 30;
    }

    protected function normalizeWorkingCapital(float $workingCapital): float
    {
        if ($workingCapital < 0) return 0;
        if ($workingCapital > 1000000) return 1;
        return $workingCapital / 1000000;
    }

    protected function normalizeRevenue(float $revenue): float
    {
        if ($revenue < 100000) return 0.3;
        if ($revenue > 10000000) return 1;
        return 0.3 + ($revenue / 10000000) * 0.7;
    }

    protected function getHealthStatus(float $score): string
    {
        if ($score >= 80) return 'excellent';
        if ($score >= 60) return 'good';
        if ($score >= 40) return 'fair';
        if ($score >= 20) return 'poor';
        return 'critical';
    }
}

