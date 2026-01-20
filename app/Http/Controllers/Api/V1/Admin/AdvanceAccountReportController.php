<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Report\AdvanceAccountReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use function trans_message;

/**
 * Контроллер отчётов по подотчётным средствам
 */
class AdvanceAccountReportController extends Controller
{
    public function __construct(
        protected AdvanceAccountReportService $reportService
    ) {
    }

    /**
     * Получить сводный отчёт по подотчётным средствам
     * 
     * GET /api/v1/admin/advance-accounts/reports/summary
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['date_from', 'date_to']);
            $filters['organization_id'] = $request->user()->current_organization_id;

            $report = $this->reportService->getSummaryReport($filters);
            
            return AdminResponse::success($report, trans_message('advance_account.summary_loaded'));
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    /**
     * Получить отчёт по подотчётным средствам конкретного пользователя
     * 
     * GET /api/v1/admin/advance-accounts/reports/user/{userId}
     */
    public function userReport(Request $request, int $userId): JsonResponse
    {
        try {
            $filters = $request->only(['date_from', 'date_to']);
            $filters['organization_id'] = $request->user()->current_organization_id;
            $filters['user_id'] = $userId;

            $report = $this->reportService->getUserReport($filters);
            
            return AdminResponse::success($report, trans_message('advance_account.user_report_loaded'));
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    /**
     * Получить отчёт по подотчётным средствам по проекту
     * 
     * GET /api/v1/admin/advance-accounts/reports/project/{projectId}
     */
    public function projectReport(Request $request, int $projectId): JsonResponse
    {
        try {
            $filters = $request->only(['date_from', 'date_to']);
            $filters['organization_id'] = $request->user()->current_organization_id;
            $filters['project_id'] = $projectId;

            $report = $this->reportService->getProjectReport($filters);
            
            return AdminResponse::success($report, trans_message('advance_account.project_report_loaded'));
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    /**
     * Получить отчёт по просроченным подотчётным средствам
     * 
     * GET /api/v1/admin/advance-accounts/reports/overdue
     */
    public function overdueReport(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['overdue_days']);
            $filters['organization_id'] = $request->user()->current_organization_id;

            $report = $this->reportService->getOverdueReport($filters);
            
            return AdminResponse::success($report, trans_message('advance_account.overdue_report_loaded'));
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }
}
