<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\ReportEngine;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\DataAggregator;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\KPICalculator;
use App\Http\Controllers\Controller;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class HoldingReportsController extends Controller
{
    private ReportEngine $reportEngine;
    private DataAggregator $dataAggregator;
    private KPICalculator $kpiCalculator;

    public function __construct(
        ReportEngine $reportEngine,
        DataAggregator $dataAggregator,
        KPICalculator $kpiCalculator
    ) {
        $this->reportEngine = $reportEngine;
        $this->dataAggregator = $dataAggregator;
        $this->kpiCalculator = $kpiCalculator;
    }

    /**
     * Основной дашборд холдинга - ГЛАВНАЯ ФУНКЦИЯ
     */
    public function getDashboard(Request $request, int $holdingId): JsonResponse
    {
        try {
            $this->validateHoldingAccess($holdingId, $request->user());
            
            $period = $request->has('period') 
                ? Carbon::parse($request->input('period'))
                : null;

            $dashboard = $this->reportEngine->generateHoldingDashboard($holdingId, $period);

            return response()->json([
                'success' => true,
                'data' => $dashboard
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Сравнение организаций внутри холдинга
     */
    public function getOrganizationsComparison(Request $request, int $holdingId): JsonResponse
    {
        try {
            $this->validateHoldingAccess($holdingId, $request->user());

            $period = $request->has('period') 
                ? Carbon::parse($request->input('period'))
                : null;

            $comparison = $this->reportEngine->generateOrganizationComparison($holdingId, $period);

            return response()->json([
                'success' => true,
                'data' => $comparison
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Финансовый отчет за период
     */
    public function getFinancialReport(Request $request, int $holdingId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $this->validateHoldingAccess($holdingId, $request->user());

            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);

            // Ограничиваем период максимум 1 годом для производительности
            if ($startDate->diffInDays($endDate) > 365) {
                return response()->json([
                    'success' => false,
                    'message' => 'Максимальный период отчета - 1 год'
                ], 422);
            }

            $report = $this->reportEngine->generateFinancialReport($holdingId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * KPI холдинга (основные показатели)
     */
    public function getKPIMetrics(Request $request, int $holdingId): JsonResponse
    {
        try {
            $this->validateHoldingAccess($holdingId, $request->user());

            $group = OrganizationGroup::findOrFail($holdingId);
            $holding = new \App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate($group);

            $period = $request->has('period') 
                ? Carbon::parse($request->input('period'))
                : null;

            $kpis = $this->kpiCalculator->calculateHoldingKPIs($holding, $period);

            return response()->json([
                'success' => true,
                'data' => [
                    'holding_id' => $holdingId,
                    'holding_name' => $holding->getName(),
                    'period' => $period?->format('Y-m-d') ?? 'current',
                    'kpis' => $kpis,
                    'organizations_count' => $holding->getOrganizationCount(),
                    'generated_at' => now()->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Быстрые метрики для виджетов дашборда
     */
    public function getQuickMetrics(Request $request, int $holdingId): JsonResponse
    {
        try {
            $this->validateHoldingAccess($holdingId, $request->user());

            $group = OrganizationGroup::findOrFail($holdingId);
            $holding = new \App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate($group);

            $metrics = $holding->getConsolidatedMetrics();
            
            // Добавляем тренды
            $currentPeriod = now();
            $previousPeriod = now()->subMonth();
            
            $currentRevenue = $this->dataAggregator->getTotalRevenue($holding, $currentPeriod);
            $previousRevenue = $this->dataAggregator->getTotalRevenue($holding, $previousPeriod);
            
            $revenueGrowth = $previousRevenue > 0 
                ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2)
                : ($currentRevenue > 0 ? 100 : 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'organizations_count' => $metrics['organizations_count'],
                    'total_users' => $metrics['total_users'],
                    'total_projects' => $metrics['total_projects'],
                    'active_contracts' => $metrics['active_contracts'],
                    'total_contracts_value' => $metrics['total_contracts_value'],
                    'current_revenue' => $currentRevenue,
                    'revenue_growth' => $revenueGrowth,
                    'efficiency_metrics' => $metrics['efficiency_metrics'],
                    'updated_at' => now()->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Очистка кэша отчетов (только для владельцев)
     */
    public function clearCache(Request $request, int $holdingId): JsonResponse
    {
        try {
            $this->validateHoldingAccess($holdingId, $request->user());

            // Проверяем, что пользователь - владелец организации
            $user = $request->user();
            $group = OrganizationGroup::findOrFail($holdingId);
            $parentOrg = $group->parentOrganization;
            
            $isOwner = $parentOrg->users()
                ->wherePivot('user_id', $user->id)
                ->wherePivot('is_owner', true)
                ->exists();
                
            if (!$isOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Только владельцы могут очищать кэш'
                ], 403);
            }

            $this->dataAggregator->clearHoldingCache($holdingId);
            
            // Очистка кэша для всех организаций холдинга
            $holding = new \App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate($group);
            
            foreach ($holding->getAllOrganizations() as $organization) {
                $this->dataAggregator->clearOrganizationCache($organization->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Кэш отчетов успешно очищен'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Проверка доступа к холдингу
     */
    private function validateHoldingAccess(int $holdingId, $user): void
    {
        $group = OrganizationGroup::find($holdingId);
        
        if (!$group) {
            throw new \Exception('Холдинг не найден', 404);
        }

        // Проверяем, что пользователь имеет доступ к холдингу
        $userOrgId = $user->current_organization_id;
        $parentOrgId = $group->parent_organization_id;

        // Пользователь может быть в родительской организации или в одной из дочерних
        $hasAccess = $userOrgId === $parentOrgId || 
                     \App\Models\Organization::find($userOrgId)?->parent_organization_id === $parentOrgId;

        if (!$hasAccess) {
            throw new \Exception('Нет доступа к данному холдингу', 403);
        }
    }
}