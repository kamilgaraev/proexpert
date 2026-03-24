<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisReport;
use App\BusinessModules\Features\AIAssistant\Services\SystemAnalysisExportService;
use App\BusinessModules\Features\AIAssistant\Services\SystemAnalysisService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class SystemAnalysisController extends Controller
{
    public function __construct(
        protected SystemAnalysisService $analysisService
    ) {}

    public function analyzeProject(Request $request, int $projectId): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->input('organization_id') ?? ($user->current_organization_id ?? null);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.organization_missing'), 400);
        }

        $validated = $request->validate([
            'organization_id' => 'sometimes|integer|exists:organizations,id',
            'use_cache' => 'sometimes|boolean',
            'sections' => 'sometimes|array',
        ]);

        try {
            $result = $this->analysisService->analyzeProject(
                $projectId,
                $organizationId,
                $user,
                $validated
            );

            return AdminResponse::success($result);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('errors.resource_not_found'), 404);
        } catch (Throwable $e) {
            Log::error('system_analysis.analyze_project_failed', [
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user?->id,
                'payload' => $validated,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.analyze_project_error'), 500);
        }
    }

    public function analyzeOrganization(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->input('organization_id') ?? ($user->current_organization_id ?? null);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.organization_missing'), 400);
        }

        $validated = $request->validate([
            'organization_id' => 'sometimes|integer|exists:organizations,id',
            'sections' => 'sometimes|array',
        ]);

        try {
            $result = $this->analysisService->analyzeOrganization(
                $organizationId,
                $user,
                $validated
            );

            return AdminResponse::success($result);
        } catch (\Exception $e) {
            Log::error('system_analysis.analyze_organization_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user?->id,
                'payload' => $validated,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.analyze_organization_error'), 500);
        }
    }

    public function listReports(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->input('organization_id') ?? ($user->current_organization_id ?? null);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.organization_missing'), 400);
        }

        try {
            $query = SystemAnalysisReport::forOrganization($organizationId)
                ->completed()
                ->with(['project', 'createdBy'])
                ->latest();

            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            if ($request->has('analysis_type')) {
                $query->where('analysis_type', $request->analysis_type);
            }

            if ($request->has('status')) {
                $status = (string) $request->status;

                if (in_array($status, ['good', 'warning', 'critical'], true)) {
                    $query->where('overall_status', $status);
                } else {
                    $query->where('status', $status);
                }
            }

            $reports = $query->paginate((int) $request->get('per_page', 15));

            return AdminResponse::paginated(
                $reports->items(),
                [
                    'total' => $reports->total(),
                    'per_page' => $reports->perPage(),
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('system_analysis.list_reports_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user?->id,
                'query' => $request->query(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.reports_load_error'), 500);
        }
    }

    public function getReport(int $reportId): JsonResponse
    {
        try {
            return AdminResponse::success($this->analysisService->getReport($reportId));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.report_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('system_analysis.get_report_failed', [
                'report_id' => $reportId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.report_load_error'), 500);
        }
    }

    public function recalculate(int $reportId): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->analysisService->recalculate($reportId),
                trans_message('ai_assistant.system_analysis.recalculated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.report_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('system_analysis.recalculate_failed', [
                'report_id' => $reportId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.recalculate_error'), 500);
        }
    }

    public function exportPDF(int $reportId): Response
    {
        try {
            $report = SystemAnalysisReport::with(['project', 'analysisSections'])->findOrFail($reportId);
            $pdfPath = app(SystemAnalysisExportService::class)->exportToPDF($report);

            return response()->download($pdfPath)->deleteFileAfterSend();
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.report_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('system_analysis.export_pdf_failed', [
                'report_id' => $reportId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.export_error'), 500);
        }
    }

    public function compare(int $reportId, int $previousReportId): JsonResponse
    {
        try {
            return AdminResponse::success($this->analysisService->compareReports($reportId, $previousReportId));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.report_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('system_analysis.compare_failed', [
                'report_id' => $reportId,
                'previous_report_id' => $previousReportId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.compare_error'), 500);
        }
    }

    public function deleteReport(int $reportId): JsonResponse
    {
        try {
            $report = SystemAnalysisReport::findOrFail($reportId);
            $report->delete();

            return AdminResponse::success(null, trans_message('ai_assistant.system_analysis.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.report_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('system_analysis.delete_report_failed', [
                'report_id' => $reportId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            report($e);

            return AdminResponse::error(trans_message('ai_assistant.system_analysis.delete_error'), 500);
        }
    }
}
