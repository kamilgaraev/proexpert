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
use App\Http\Requests\Api\V1\Admin\Report\ContractPaymentsReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\ContractorSettlementsReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\WarehouseStockReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\MaterialMovementsReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\TimeTrackingReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\ProjectProfitabilityReportRequest;
use App\Http\Requests\Api\V1\Admin\Report\ProjectTimelinesReportRequest;
use App\Services\Landing\OrganizationModuleService;

// TODO: Добавить Request классы для валидации фильтров отчетов

class ReportController extends Controller
{
    protected ReportService $reportService;
    protected OrganizationModuleService $moduleService;

    public function __construct(ReportService $reportService, OrganizationModuleService $moduleService)
    {
        $this->reportService = $reportService;
        $this->moduleService = $moduleService;
    }

    /**
     * Проверка доступности модуля базовых отчетов.
     */
    public function checkBasicReportsAvailability(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $hasAccess = $this->moduleService->hasModuleAccess($organizationId, 'basic_reports');

        return response()->json([
            'success' => true,
            'has_access' => $hasAccess,
            'module' => 'basic_reports',
            'features' => $hasAccess ? ['Отчет по материалам', 'Отчет по работам', 'Сводный отчет', 'Экспорт в PDF'] : []
        ]);
    }

    /**
     * Проверка доступности модуля продвинутых отчетов.
     */
    public function checkAdvancedReportsAvailability(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $hasAccess = $this->moduleService->hasModuleAccess($organizationId, 'advanced_reports');

        return response()->json([
            'success' => true,
            'has_access' => $hasAccess,
            'module' => 'advanced_reports',
            'features' => $hasAccess ? ['Активность прорабов', 'Официальные отчеты', 'Конструктор отчетов', 'Автоматизация'] : []
        ]);
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

    public function contractPaymentsReport(ContractPaymentsReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getContractPaymentsReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    public function contractorSettlementsReport(ContractorSettlementsReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getContractorSettlementsReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    public function warehouseStockReport(WarehouseStockReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getWarehouseStockReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    public function materialMovementsReport(MaterialMovementsReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getMaterialMovementsReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    public function timeTrackingReport(TimeTrackingReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getTimeTrackingReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    public function projectProfitabilityReport(ProjectProfitabilityReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getProjectProfitabilityReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }

    public function projectTimelinesReport(ProjectTimelinesReportRequest $request): JsonResponse | StreamedResponse
    {
        $reportOutput = $this->reportService->getProjectTimelinesReport($request);

        if ($reportOutput instanceof StreamedResponse) {
            return $reportOutput;
        }

        return response()->json($reportOutput);
    }
} 