<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\ReportEngine;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\DataAggregator;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\KPICalculator;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

use function trans_message;

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

            return $this->landingResponse([
                'success' => true,
                'data' => $dashboard
            ]);

        } catch (\Exception $e) {
            Log::error('Holding dashboard error', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'db_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.dashboard_error'),
            ], 500);
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

            return $this->landingResponse([
                'success' => true,
                'data' => $comparison
            ]);

        } catch (\Exception $e) {
            Log::error('Holding comparison error', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'db_code' => $e->getCode()
            ]);
            
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.comparison_error'),
            ], 500);
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
                return $this->landingResponse([
                    'success' => false,
                    'message' => trans_message('landing.holding_reports.period_too_long'),
                ], 422);
            }

            $report = $this->reportEngine->generateFinancialReport($holdingId, $startDate, $endDate);

            return $this->landingResponse([
                'success' => true,
                'data' => $report
            ]);

        } catch (ValidationException $e) {
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.validation_error'),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Holding financial report error', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'db_code' => $e->getCode()
            ]);
            
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.financial_error'),
            ], 500);
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

            return $this->landingResponse([
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
            Log::error('Holding KPI error', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'db_code' => $e->getCode()
            ]);
            
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.kpi_error'),
            ], 500);
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

            return $this->landingResponse([
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
            Log::error('Holding quick metrics error', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'db_code' => $e->getCode()
            ]);
            
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.quick_metrics_error'),
            ], 500);
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
                return $this->landingResponse([
                    'success' => false,
                    'message' => trans_message('landing.holding_reports.cache_clear_forbidden'),
                ], 403);
            }

            $this->dataAggregator->clearHoldingCache($holdingId);
            
            // Очистка кэша для всех организаций холдинга
            $holding = new \App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate($group);
            
            foreach ($holding->getAllOrganizations() as $organization) {
                $this->dataAggregator->clearOrganizationCache($organization->id);
            }

            return $this->landingResponse([
                'success' => true,
                'message' => trans_message('landing.holding_reports.cache_cleared'),
            ]);

        } catch (\Exception $e) {
            Log::error('Holding cache clear error', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'db_code' => $e->getCode()
            ]);
            
            return $this->landingResponse([
                'success' => false,
                'message' => trans_message('landing.holding_reports.cache_clear_error'),
            ], 500);
        }
    }

    /**
     * Проверка доступа к холдингу
     */
    private function validateHoldingAccess(int $holdingId, $user): void
    {
        $group = OrganizationGroup::find($holdingId);
        
        if (!$group) {
            throw new \Exception('Холдинг не найден');
        }

        // Проверяем, что пользователь имеет доступ к холдингу
        $userOrgId = $user->current_organization_id;
        $parentOrgId = $group->parent_organization_id;

        // Пользователь может быть в родительской организации или в одной из дочерних
        $hasAccess = $userOrgId === $parentOrgId || 
                     \App\Models\Organization::find($userOrgId)?->parent_organization_id === $parentOrgId;

        if (!$hasAccess) {
            throw new \Exception('Нет доступа к данному холдингу');
        }
    }
    private function landingResponse(array $payload, int $status = 200): JsonResponse
    {
        $success = (bool) ($payload['success'] ?? true);
        $message = $payload['message'] ?? null;

        unset($payload['success'], $payload['message']);

        if ($success) {
            $data = array_key_exists('data', $payload) && count($payload) === 1
                ? $payload['data']
                : $payload;

            return LandingResponse::success($data, $message, $status);
        }

        $errors = $payload['errors'] ?? null;
        unset($payload['errors']);

        return LandingResponse::error((string) $message, $status, $errors, $payload);
    }

}
