<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Report\AdvanceAccountReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdvanceAccountReportController extends Controller
{
    protected $reportService;

    /**
     * Конструктор контроллера.
     *
     * @param AdvanceAccountReportService $reportService
     */
    public function __construct(AdvanceAccountReportService $reportService)
    {
        $this->reportService = $reportService;
        // Авторизация настроена на уровне роутов через middleware стек
    }

    /**
     * Получить сводный отчет по подотчетным средствам.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->only([
            'date_from', 'date_to'
        ]);
        
        // Устанавливаем текущую организацию
        $filters['organization_id'] = Auth::user()->current_organization_id;

        $report = $this->reportService->getSummaryReport($filters);
        return response()->json($report);
    }

    /**
     * Получить отчет по подотчетным средствам конкретного пользователя.
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function userReport(Request $request, int $userId): JsonResponse
    {
        $filters = $request->only([
            'date_from', 'date_to'
        ]);
        
        // Устанавливаем текущую организацию и пользователя
        $filters['organization_id'] = Auth::user()->current_organization_id;
        $filters['user_id'] = $userId;

        $report = $this->reportService->getUserReport($filters);
        return response()->json($report);
    }

    /**
     * Получить отчет по подотчетным средствам по проекту.
     *
     * @param Request $request
     * @param int $projectId
     * @return JsonResponse
     */
    public function projectReport(Request $request, int $projectId): JsonResponse
    {
        $filters = $request->only([
            'date_from', 'date_to'
        ]);
        
        // Устанавливаем текущую организацию и проект
        $filters['organization_id'] = Auth::user()->current_organization_id;
        $filters['project_id'] = $projectId;

        $report = $this->reportService->getProjectReport($filters);
        return response()->json($report);
    }

    /**
     * Получить отчет по просроченным подотчетным средствам.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function overdueReport(Request $request): JsonResponse
    {
        $filters = $request->only([
            'overdue_days'
        ]);
        
        // Устанавливаем текущую организацию
        $filters['organization_id'] = Auth::user()->current_organization_id;

        $report = $this->reportService->getOverdueReport($filters);
        return response()->json($report);
    }

    /**
     * Экспорт отчета в указанном формате.
     *
     * @param Request $request
     * @param string $format
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function export(Request $request, string $format)
    {
        $filters = $request->only([
            'date_from', 'date_to', 'user_id', 'project_id', 'report_type'
        ]);
        
        // Устанавливаем текущую организацию
        $filters['organization_id'] = Auth::user()->current_organization_id;

        return $this->reportService->exportReport($filters, $format);
    }
} 