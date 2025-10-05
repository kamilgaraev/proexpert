<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AdvancedDashboard\Services\FinancialAnalyticsService;
use App\BusinessModules\Features\AdvancedDashboard\Services\PredictiveAnalyticsService;
use App\BusinessModules\Features\AdvancedDashboard\Services\KPICalculationService;

/**
 * Контроллер аналитики дашборда
 * 
 * Endpoints для финансовой, предиктивной и HR аналитики
 */
class AdvancedDashboardController extends Controller
{
    protected FinancialAnalyticsService $financialService;
    protected PredictiveAnalyticsService $predictiveService;
    protected KPICalculationService $kpiService;

    public function __construct(
        FinancialAnalyticsService $financialService,
        PredictiveAnalyticsService $predictiveService,
        KPICalculationService $kpiService
    ) {
        $this->financialService = $financialService;
        $this->predictiveService = $predictiveService;
        $this->kpiService = $kpiService;
    }

    // ==================== FINANCIAL ANALYTICS ====================

    /**
     * Получить Cash Flow (движение денежных средств)
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/financial/cash-flow
     */
    public function getCashFlow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $projectId = $validated['project_id'] ?? null;
        
        $data = $this->financialService->getCashFlow(
            $organizationId,
            $from,
            $to,
            $projectId
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить Profit & Loss (прибыли и убытки)
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/financial/profit-loss
     */
    public function getProfitLoss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $projectId = $validated['project_id'] ?? null;
        
        $data = $this->financialService->getProfitAndLoss(
            $organizationId,
            $from,
            $to,
            $projectId
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить ROI (рентабельность инвестиций)
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/financial/roi
     */
    public function getROI(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $projectId = $validated['project_id'] ?? null;
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : null;
        
        $data = $this->financialService->getROI(
            $organizationId,
            $projectId,
            $from,
            $to
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить Revenue Forecast (прогноз доходов)
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/financial/revenue-forecast
     */
    public function getRevenueForecast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:24',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $months = $validated['months'] ?? 6;
        
        $data = $this->financialService->getRevenueForecast(
            $organizationId,
            $months
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить Receivables & Payables (дебиторка/кредиторка)
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/financial/receivables-payables
     */
    public function getReceivablesPayables(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $data = $this->financialService->getReceivablesPayables($organizationId);
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ==================== PREDICTIVE ANALYTICS ====================

    /**
     * Получить прогноз завершения контракта
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/predictive/contract-forecast
     */
    public function getContractForecast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
        ]);
        
        $data = $this->predictiveService->predictContractCompletion(
            $validated['contract_id']
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить риски превышения бюджета
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/predictive/budget-risk
     */
    public function getBudgetRisk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
        ]);
        
        $data = $this->predictiveService->predictBudgetOverrun(
            $validated['project_id']
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить прогноз потребности в материалах
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/predictive/material-needs
     */
    public function getMaterialNeeds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:12',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $months = $validated['months'] ?? 3;
        
        $data = $this->predictiveService->predictMaterialNeeds(
            $organizationId,
            $months
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ==================== HR & KPI ANALYTICS ====================

    /**
     * Получить KPI сотрудника
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/hr/kpi
     */
    public function getKPI(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $userId = $validated['user_id'] ?? $user?->id ?? 0;
        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        
        $data = $this->kpiService->calculateUserKPI(
            $userId,
            $organizationId,
            $from,
            $to
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить топ исполнителей
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/hr/top-performers
     */
    public function getTopPerformers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $limit = $validated['limit'] ?? 10;
        
        $data = $this->kpiService->getTopPerformers(
            $organizationId,
            $from,
            $to,
            $limit
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Получить загрузку ресурсов (утилизацию)
     * 
     * GET /api/v1/admin/advanced-dashboard/analytics/hr/resource-utilization
     */
    public function getResourceUtilization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        
        $data = $this->kpiService->getResourceUtilization(
            $organizationId,
            $from,
            $to
        );
        
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}

