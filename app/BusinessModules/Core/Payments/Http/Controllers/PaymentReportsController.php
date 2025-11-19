<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Services\Reports\AgingAnalysisReportService;
use App\BusinessModules\Core\Payments\Services\Reports\CashFlowReportService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReportsController extends Controller
{
    public function __construct(
        private readonly CashFlowReportService $cashFlowService,
        private readonly AgingAnalysisReportService $agingService
    ) {}

    /**
     * Отчет Cash Flow
     */
    public function cashFlow(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $dateFrom = Carbon::parse($validated['date_from']);
            $dateTo = Carbon::parse($validated['date_to']);

            $report = $this->cashFlowService->generate($organizationId, $dateFrom, $dateTo);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_reports.cash_flow.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось сформировать отчет',
            ], 500);
        }
    }

    /**
     * Отчет Aging Analysis
     */
    public function agingAnalysis(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'as_of_date' => 'nullable|date',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $asOfDate = isset($validated['as_of_date']) 
                ? Carbon::parse($validated['as_of_date']) 
                : null;

            $report = $this->agingService->generate($organizationId, $asOfDate);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_reports.aging_analysis.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось сформировать отчет',
            ], 500);
        }
    }

    /**
     * Критические контрагенты (с большой просрочкой)
     */
    public function criticalContractors(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $minDaysOverdue = $request->input('min_days_overdue', 90);

            $report = $this->agingService->getCriticalContractors($organizationId, $minDaysOverdue);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_reports.critical_contractors.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось сформировать отчет',
            ], 500);
        }
    }
}

