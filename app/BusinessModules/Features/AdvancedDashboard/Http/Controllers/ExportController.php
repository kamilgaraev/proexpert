<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardExportService;
use App\BusinessModules\Features\AdvancedDashboard\Models\ScheduledReport;
use App\Services\LogService;

/**
 * Контроллер экспорта дашбордов
 */
class ExportController extends Controller
{
    protected DashboardExportService $exportService;

    public function __construct(DashboardExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Экспортировать дашборд в PDF
     * 
     * POST /api/v1/admin/advanced-dashboard/export/dashboard/{id}/pdf
     */
    public function exportToPDF(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'filters' => 'nullable|array',
            'widgets' => 'nullable|array',
        ]);
        
        try {
            $filePath = $this->exportService->exportDashboardToPDF($id, $validated);
            
            LogService::info('Dashboard exported to PDF', [
                'dashboard_id' => $id,
                'file_path' => $filePath,
            ]);
            
            $url = Storage::url($filePath);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard exported successfully',
                'data' => [
                    'file_path' => $filePath,
                    'file_url' => $url,
                    'format' => 'pdf',
                ],
            ]);
            
        } catch (\Exception $e) {
            LogService::error('Failed to export dashboard', [
                'dashboard_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Экспортировать дашборд в Excel
     * 
     * POST /api/v1/admin/advanced-dashboard/export/dashboard/{id}/excel
     */
    public function exportToExcel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'filters' => 'nullable|array',
            'widgets' => 'nullable|array',
            'include_raw_data' => 'nullable|boolean',
        ]);
        
        try {
            $filePath = $this->exportService->exportDashboardToExcel($id, $validated);
            
            LogService::info('Dashboard exported to Excel', [
                'dashboard_id' => $id,
                'file_path' => $filePath,
            ]);
            
            $url = Storage::url($filePath);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard exported successfully',
                'data' => [
                    'file_path' => $filePath,
                    'file_url' => $url,
                    'format' => 'excel',
                ],
            ]);
            
        } catch (\Exception $e) {
            LogService::error('Failed to export dashboard', [
                'dashboard_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список scheduled reports
     * 
     * GET /api/v1/admin/advanced-dashboard/export/scheduled-reports
     */
    public function listScheduledReports(Request $request): JsonResponse
    {
        $organizationId = $request->header('X-Organization-ID');
        $userId = Auth::id() ?? 0;
        
        $query = ScheduledReport::forUser($userId)
            ->forOrganization($organizationId);
        
        if ($request->has('is_active')) {
            $isActive = $request->boolean('is_active');
            if ($isActive) {
                $query->active();
            }
        }
        
        if ($request->has('frequency')) {
            $query->byFrequency($request->input('frequency'));
        }
        
        $reports = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Создать scheduled report
     * 
     * POST /api/v1/admin/advanced-dashboard/export/scheduled-reports
     */
    public function createScheduledReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dashboard_id' => 'required|integer|exists:dashboards,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'frequency' => 'required|string|in:daily,weekly,monthly,custom',
            'cron_expression' => 'nullable|string',
            'time_of_day' => 'nullable|date_format:H:i:s',
            'days_of_week' => 'nullable|array',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'export_formats' => 'required|array',
            'export_formats.*' => 'string|in:pdf,excel',
            'recipients' => 'required|array',
            'recipients.*' => 'email',
            'cc_recipients' => 'nullable|array',
            'cc_recipients.*' => 'email',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string',
            'filters' => 'nullable|array',
            'widgets' => 'nullable|array',
            'include_raw_data' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);
        
        $userId = Auth::id() ?? 0;
        $organizationId = $request->header('X-Organization-ID');
        
        try {
            $report = ScheduledReport::create(array_merge($validated, [
                'user_id' => $userId,
                'organization_id' => $organizationId,
            ]));
            
            LogService::info('Scheduled report created', [
                'report_id' => $report->id,
                'frequency' => $report->frequency,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Scheduled report created successfully',
                'data' => $report,
            ], 201);
            
        } catch (\Exception $e) {
            LogService::error('Failed to create scheduled report', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Обновить scheduled report
     * 
     * PUT /api/v1/admin/advanced-dashboard/export/scheduled-reports/{id}
     */
    public function updateScheduledReport(Request $request, int $id): JsonResponse
    {
        $report = ScheduledReport::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'frequency' => 'sometimes|required|string|in:daily,weekly,monthly,custom',
            'time_of_day' => 'nullable|date_format:H:i:s',
            'export_formats' => 'sometimes|required|array',
            'recipients' => 'sometimes|required|array',
            'is_active' => 'nullable|boolean',
        ]);
        
        try {
            $report->update($validated);
            
            LogService::info('Scheduled report updated', [
                'report_id' => $report->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Scheduled report updated successfully',
                'data' => $report->fresh(),
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Удалить scheduled report
     * 
     * DELETE /api/v1/admin/advanced-dashboard/export/scheduled-reports/{id}
     */
    public function deleteScheduledReport(int $id): JsonResponse
    {
        try {
            $report = ScheduledReport::findOrFail($id);
            $report->delete();
            
            LogService::info('Scheduled report deleted', [
                'report_id' => $id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Scheduled report deleted successfully',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Получить доступные форматы экспорта
     * 
     * GET /api/v1/admin/advanced-dashboard/export/formats
     */
    public function getAvailableFormats(): JsonResponse
    {
        $formats = $this->exportService->getAvailableFormats();
        
        return response()->json([
            'success' => true,
            'data' => $formats,
        ]);
    }
}

