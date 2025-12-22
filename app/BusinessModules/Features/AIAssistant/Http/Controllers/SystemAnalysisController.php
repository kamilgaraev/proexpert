<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Services\SystemAnalysisService;
use App\BusinessModules\Features\AIAssistant\Services\SystemAnalysisExportService;
use App\BusinessModules\Features\AIAssistant\Models\SystemAnalysisReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SystemAnalysisController extends Controller
{
    protected SystemAnalysisService $analysisService;
    protected SystemAnalysisExportService $exportService;

    public function __construct(
        SystemAnalysisService $analysisService,
        SystemAnalysisExportService $exportService
    ) {
        $this->analysisService = $analysisService;
        $this->exportService = $exportService;
    }

    /**
     * Анализ одного проекта
     *
     * POST /api/v1/ai-assistant/system-analysis/projects/{project}/analyze
     */
    public function analyzeProject(Request $request, int $projectId)
    {
        $user = Auth::user();
        $organizationId = $request->organization_id ?? $user->organization_id;

        // Валидация
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

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при анализе проекта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Анализ всех проектов организации
     *
     * POST /api/v1/ai-assistant/system-analysis/organization/analyze
     */
    public function analyzeOrganization(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->organization_id ?? $user->organization_id;

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

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при анализе организации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Список отчетов
     *
     * GET /api/v1/ai-assistant/system-analysis/reports
     */
    public function listReports(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->organization_id ?? $user->organization_id;

        $query = SystemAnalysisReport::forOrganization($organizationId)
            ->with(['project', 'createdBy'])
            ->latest();

        // Фильтры
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('analysis_type')) {
            $query->where('analysis_type', $request->analysis_type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Пагинация
        $perPage = $request->get('per_page', 15);
        $reports = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $reports->items(),
            'pagination' => [
                'total' => $reports->total(),
                'per_page' => $reports->perPage(),
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }

    /**
     * Получить отчет
     *
     * GET /api/v1/ai-assistant/system-analysis/reports/{report}
     */
    public function getReport(int $reportId)
    {
        try {
            $result = $this->analysisService->getReport($reportId);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }
    }

    /**
     * Пересчитать анализ
     *
     * POST /api/v1/ai-assistant/system-analysis/reports/{report}/recalculate
     */
    public function recalculate(int $reportId)
    {
        try {
            $result = $this->analysisService->recalculate($reportId);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Анализ успешно пересчитан',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при пересчете: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Экспорт в PDF
     *
     * GET /api/v1/ai-assistant/system-analysis/reports/{report}/export/pdf
     */
    public function exportPDF(int $reportId)
    {
        try {
            $report = SystemAnalysisReport::with(['project', 'analysisSections'])
                ->findOrFail($reportId);

            $pdfPath = $this->exportService->exportToPDF($report);

            return response()->download($pdfPath)->deleteFileAfterSend();

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при экспорте: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Сравнить два отчета
     *
     * GET /api/v1/ai-assistant/system-analysis/reports/{report}/compare/{previous}
     */
    public function compare(int $reportId, int $previousReportId)
    {
        try {
            $comparison = $this->analysisService->compareReports($reportId, $previousReportId);

            return response()->json([
                'success' => true,
                'data' => $comparison,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при сравнении: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить отчет
     *
     * DELETE /api/v1/ai-assistant/system-analysis/reports/{report}
     */
    public function deleteReport(int $reportId)
    {
        try {
            $report = SystemAnalysisReport::findOrFail($reportId);
            $report->delete();

            return response()->json([
                'success' => true,
                'message' => 'Отчет успешно удален',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении: ' . $e->getMessage(),
            ], 500);
        }
    }
}

