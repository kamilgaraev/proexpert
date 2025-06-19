<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
// use Illuminate\Http\Request; // Убираем
use Illuminate\Http\JsonResponse;
use App\Services\Report\ReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;

// Импортируем созданные Request классы
use App\Http\Requests\Api\V1\Admin\Report\MaterialUsageReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\WorkCompletionReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\ForemanActivityReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\ProjectStatusSummaryReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\OfficialMaterialUsageReportRequest;

// TODO: Добавить Request классы для валидации фильтров отчетов

class ReportController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
        $this->middleware('can:view-reports');
    }

    /**
     * Отчет по расходу материалов на объектах.
     */
    public function materialUsageReport(MaterialUsageReportRequest $request): JsonResponse | StreamedResponse
    {
        // TODO: Получить фильтры (project_id, date_from, date_to, material_id)
        $reportOutput = $this->reportService->getMaterialUsageReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    /**
     * Отчет по выполненным работам.
     */
    public function workCompletionReport(WorkCompletionReportRequest $request): JsonResponse | StreamedResponse
    {
        // TODO: Получить фильтры (project_id, date_from, date_to, work_type_id)
        $reportOutput = $this->reportService->getWorkCompletionReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    /**
     * Отчет по активности прорабов.
     */
    public function foremanActivityReport(ForemanActivityReportRequest $request): JsonResponse | StreamedResponse
    {
        // TODO: Получить фильтры (user_id, date_from, date_to)
        $reportData = $this->reportService->getForemanActivityReport($request);
        
        if ($reportData instanceof StreamedResponse) {
            return $reportData;
        }
        
        return response()->json($reportData);
    }

    /**
     * Сводный отчет по статусам проектов.
     */
    public function projectStatusSummaryReport(ProjectStatusSummaryReportRequest $request): JsonResponse
    {
        // TODO: Получить фильтры (status, date_from, date_to, is_archived)
        $reportData = $this->reportService->getProjectStatusSummaryReport($request);
        return response()->json($reportData);
    }

    /**
     * Официальный отчет об использовании материалов, переданных Заказчиком.
     */
    public function officialMaterialUsageReport(OfficialMaterialUsageReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportData = $this->reportService->getOfficialMaterialUsageReport($request);
        
        if ($reportData instanceof StreamedResponse) {
            return $reportData;
        }
        
        return response()->json($reportData);
    }
} 