<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Report\AdvanceAccountReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

class AdvanceAccountReportController extends Controller
{
    public function __construct(
        protected AdvanceAccountReportService $reportService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getSummaryReport($this->filters($request, ['date_from', 'date_to']));
            $this->storeReportSnapshot($report, 'advance_account_summary_report');

            return AdminResponse::success($report, trans_message('advance_account.summary_loaded'));
        } catch (\Throwable $exception) {
            $this->logReportError($exception, $request, 'summary');

            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    public function userReport(Request $request, int $userId): JsonResponse
    {
        try {
            $filters = $this->filters($request, ['date_from', 'date_to']);
            $filters['user_id'] = $userId;
            $report = $this->reportService->getUserReport($filters);
            $this->storeReportSnapshot($report, 'advance_account_user_report');

            return AdminResponse::success($report, trans_message('advance_account.user_report_loaded'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('advance_account.report_subject_not_found'), 404);
        } catch (\Throwable $exception) {
            $this->logReportError($exception, $request, 'user', ['user_id' => $userId]);

            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    public function projectReport(Request $request, int $projectId): JsonResponse
    {
        try {
            $filters = $this->filters($request, ['date_from', 'date_to']);
            $filters['project_id'] = $projectId;
            $report = $this->reportService->getProjectReport($filters);
            $this->storeReportSnapshot($report, 'advance_account_project_report');

            return AdminResponse::success($report, trans_message('advance_account.project_report_loaded'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('advance_account.report_subject_not_found'), 404);
        } catch (\Throwable $exception) {
            $this->logReportError($exception, $request, 'project', ['project_id' => $projectId]);

            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    public function overdueReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getOverdueReport($this->filters($request, ['overdue_days']));
            $this->storeReportSnapshot($report, 'advance_account_overdue_report');

            return AdminResponse::success($report, trans_message('advance_account.overdue_report_loaded'));
        } catch (\Throwable $exception) {
            $this->logReportError($exception, $request, 'overdue');

            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    public function export(Request $request, string $format): Response|StreamedResponse|JsonResponse
    {
        if (!in_array($format, ['json', 'csv', 'xlsx'], true)) {
            return AdminResponse::error(trans_message('reports.unsupported_export_format'), 422);
        }

        try {
            $filters = $this->filters($request, [
                'date_from',
                'date_to',
                'overdue_days',
                'report_type',
                'user_id',
                'project_id',
            ]);

            return $this->reportService->exportReport($filters, $format);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('advance_account.report_subject_not_found'), 404);
        } catch (\Throwable $exception) {
            $this->logReportError($exception, $request, 'export', ['format' => $format]);

            return AdminResponse::error(trans_message('advance_account.report_failed'), 500);
        }
    }

    private function filters(Request $request, array $keys): array
    {
        $filters = array_filter(
            $request->only($keys),
            static fn ($value): bool => $value !== null && $value !== ''
        );

        $filters['organization_id'] = $request->user()->current_organization_id;

        return $filters;
    }

    private function storeReportSnapshot(array $report, string $baseFilename): void
    {
        if (!$this->reportService->storeJsonReportSnapshot($report, $baseFilename)) {
            throw new \RuntimeException(trans_message('reports.storage_failed'));
        }
    }

    private function logReportError(
        \Throwable $exception,
        Request $request,
        string $reportType,
        array $context = []
    ): void {
        Log::error('Advance account report failed.', array_merge($context, [
            'report_type' => $reportType,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->current_organization_id,
            'filters' => $request->query(),
            'exception' => $exception,
        ]));
    }
}
