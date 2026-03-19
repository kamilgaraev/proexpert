<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Services\Reports\AgingAnalysisReportService;
use App\BusinessModules\Core\Payments\Services\Reports\CashFlowReportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PaymentReportsController extends Controller
{
    public function __construct(
        private readonly CashFlowReportService $cashFlowService,
        private readonly AgingAnalysisReportService $agingService
    ) {}

    public function cashFlow(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => ['required', 'date'],
                'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $report = $this->cashFlowService->generate(
                $organizationId,
                Carbon::parse($validated['date_from']),
                Carbon::parse($validated['date_to'])
            );

            return AdminResponse::success($report, trans_message('payments.reports.generated'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_reports.cash_flow.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.reports.generate_error'), 500);
        }
    }

    public function agingAnalysis(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'as_of_date' => ['nullable', 'date'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $report = $this->agingService->generate(
                $organizationId,
                isset($validated['as_of_date']) ? Carbon::parse($validated['as_of_date']) : null
            );

            return AdminResponse::success($report, trans_message('payments.reports.generated'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_reports.aging_analysis.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.reports.generate_error'), 500);
        }
    }

    public function criticalContractors(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'min_days_overdue' => ['nullable', 'integer', 'min:1'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $report = $this->agingService->getCriticalContractors(
                $organizationId,
                (int) ($validated['min_days_overdue'] ?? 90)
            );

            return AdminResponse::success($report, trans_message('payments.reports.generated'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payment_reports.critical_contractors.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.reports.generate_error'), 500);
        }
    }
}
