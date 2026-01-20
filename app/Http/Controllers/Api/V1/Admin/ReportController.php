<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use App\Services\Report\ReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;

// Request классы для валидации
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

use function trans_message;

/**
 * Контроллер отчётов
 * 
 * Thin Controller - вся логика в ReportService
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
        protected OrganizationModuleService $moduleService
    ) {
    }

    /**
     * Проверка доступности модуля базовых отчётов
     * 
     * GET /api/v1/admin/reports/check-basic-availability
     */
    public function checkBasicReportsAvailability(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->current_organization_id;

            $hasAccess = $this->moduleService->hasModuleAccess($organizationId, 'basic_reports');

            $data = [
                'has_access' => $hasAccess,
                'module' => 'basic_reports',
                'features' => $hasAccess 
                    ? ['Отчет по материалам', 'Отчет по работам', 'Сводный отчет', 'Экспорт в PDF'] 
                    : []
            ];

            return AdminResponse::success(
                $data,
                $hasAccess ? trans_message('reports.basic_reports_available') : trans_message('reports.module_not_available')
            );
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('reports.generation_failed'), 500);
        }
    }

    /**
     * Проверка доступности модуля продвинутых отчётов
     * 
     * GET /api/v1/admin/reports/check-advanced-availability
     */
    public function checkAdvancedReportsAvailability(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->current_organization_id;

            $hasAccess = $this->moduleService->hasModuleAccess($organizationId, 'advanced_reports');

            $data = [
                'has_access' => $hasAccess,
                'module' => 'advanced_reports',
                'features' => $hasAccess 
                    ? ['Активность прорабов', 'Официальные отчеты', 'Конструктор отчетов', 'Автоматизация'] 
                    : []
            ];

            return AdminResponse::success(
                $data,
                $hasAccess ? trans_message('reports.advanced_reports_available') : trans_message('reports.module_not_available')
            );
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('reports.generation_failed'), 500);
        }
    }

    /**
     * Отчёт по выполненным работам
     * 
     * GET /api/v1/admin/reports/work-completion
     */
    public function workCompletionReport(WorkCompletionReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getWorkCompletionReport($request),
            'reports.work_completion'
        );
    }

    /**
     * Отчёт по активности прорабов
     * 
     * GET /api/v1/admin/reports/foreman-activity
     */
    public function foremanActivityReport(ForemanActivityReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getForemanActivityReport($request),
            'reports.foreman_activity'
        );
    }

    /**
     * Сводный отчёт по статусам проектов
     * 
     * GET /api/v1/admin/reports/project-status-summary
     */
    public function projectStatusSummaryReport(ProjectStatusSummaryReportRequest $request): JsonResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getProjectStatusSummaryReport($request),
            'reports.project_status'
        );
    }

    /**
     * Официальный отчёт об использовании материалов
     * 
     * GET /api/v1/admin/reports/official-material-usage
     */
    public function officialMaterialUsageReport(OfficialMaterialUsageReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getOfficialMaterialUsageReport($request),
            'reports.official_material_usage'
        );
    }

    /**
     * Отчёт по оплатам контрактов
     * 
     * GET /api/v1/admin/reports/contract-payments
     */
    public function contractPaymentsReport(ContractPaymentsReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getContractPaymentsReport($request),
            'reports.contract_payments'
        );
    }

    /**
     * Отчёт по расчётам с подрядчиками
     * 
     * GET /api/v1/admin/reports/contractor-settlements
     */
    public function contractorSettlementsReport(ContractorSettlementsReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getContractorSettlementsReport($request),
            'reports.contractor_settlements'
        );
    }

    /**
     * Отчёт по складским остаткам
     * 
     * GET /api/v1/admin/reports/warehouse-stock
     */
    public function warehouseStockReport(WarehouseStockReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getWarehouseStockReport($request),
            'reports.warehouse_stock'
        );
    }

    /**
     * Отчёт по движению материалов
     * 
     * GET /api/v1/admin/reports/material-movements
     */
    public function materialMovementsReport(MaterialMovementsReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getMaterialMovementsReport($request),
            'reports.material_movements'
        );
    }

    /**
     * Отчёт по учёту рабочего времени
     * 
     * GET /api/v1/admin/reports/time-tracking
     */
    public function timeTrackingReport(TimeTrackingReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getTimeTrackingReport($request),
            'reports.time_tracking'
        );
    }

    /**
     * Отчёт по рентабельности проектов
     * 
     * GET /api/v1/admin/reports/project-profitability
     */
    public function projectProfitabilityReport(ProjectProfitabilityReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getProjectProfitabilityReport($request),
            'reports.project_profitability'
        );
    }

    /**
     * Отчёт по срокам выполнения проектов
     * 
     * GET /api/v1/admin/reports/project-timelines
     */
    public function projectTimelinesReport(ProjectTimelinesReportRequest $request): JsonResponse | StreamedResponse
    {
        return $this->generateReport(
            fn() => $this->reportService->getProjectTimelinesReport($request),
            'reports.project_timelines'
        );
    }

    /**
     * Helper метод для генерации отчётов (DRY принцип)
     * 
     * @param callable $reportGenerator Функция генерации отчёта
     * @param string $messageKey Ключ сообщения для перевода
     * @return JsonResponse|StreamedResponse
     */
    protected function generateReport(callable $reportGenerator, string $messageKey): JsonResponse | StreamedResponse
    {
        try {
            $reportOutput = $reportGenerator();

            // Если это StreamedResponse (файл для скачивания) - возвращаем как есть
            if ($reportOutput instanceof StreamedResponse) {
                return $reportOutput;
            }

            // Иначе оборачиваем в AdminResponse
            return AdminResponse::success(
                $reportOutput,
                trans_message('reports.generated')
            );
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('reports.generation_failed'),
                500
            );
        }
    }
}
