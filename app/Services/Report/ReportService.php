<?php

namespace App\Services\Report;

use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\Export\CsvExporterService;
use App\Services\Export\ExcelExporterService;
use App\Services\Export\PdfExporterService;
use App\Services\Logging\LoggingService;
use App\Models\User;
use App\Models\Role;
use App\Models\ContractPerformanceAct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Report\ReportTemplateService;
use App\Models\ReportTemplate;
use App\Services\Report\MaterialReportService;
use App\Services\RateCoefficient\RateCoefficientService;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;
use App\Models\TimeEntry;
use function trans_message;

class ReportService
{
    protected WorkCompletionLogRepositoryInterface $workLogRepo;
    protected ProjectRepositoryInterface $projectRepo;
    protected UserRepositoryInterface $userRepo;
    protected CsvExporterService $csvExporter;
    protected ExcelExporterService $excelExporter;
    protected PdfExporterService $pdfExporter;
    protected ReportTemplateService $reportTemplateService;
    protected MaterialReportService $materialReportService;
    protected RateCoefficientService $rateCoefficientService;
    protected LoggingService $logging;

    public function __construct(
        WorkCompletionLogRepositoryInterface $workLogRepo,
        ProjectRepositoryInterface $projectRepo,
        UserRepositoryInterface $userRepo,
        CsvExporterService $csvExporter,
        ExcelExporterService $excelExporter,
        PdfExporterService $pdfExporter,
        ReportTemplateService $reportTemplateService,
        MaterialReportService $materialReportService,
        RateCoefficientService $rateCoefficientService,
        LoggingService $logging
    ) {
        $this->workLogRepo = $workLogRepo;
        $this->projectRepo = $projectRepo;
        $this->userRepo = $userRepo;
        $this->csvExporter = $csvExporter;
        $this->excelExporter = $excelExporter;
        $this->pdfExporter = $pdfExporter;
        $this->reportTemplateService = $reportTemplateService;
        $this->materialReportService = $materialReportService;
        $this->rateCoefficientService = $rateCoefficientService;
        $this->logging = $logging;
    }

    /**
     * Helper для получения ID текущей организации администратора.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var User|null $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId) {
            Log::error('Failed to determine organization context in ReportService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен для отчетов.', 500);
        }
        return (int)$organizationId;
    }

    /**
     * Подготовка фильтров для отчетов (даты, проект, пользователь и т.д.)
     */
    protected function prepareReportFilters(Request $request, array $allowedFilters): array
    {
        $filters = [];
        foreach ($allowedFilters as $key) {
            if ($request->has($key) && !is_null($request->query($key)) && $request->query($key) !== '') {
                $filters[$key] = $request->query($key);
            }
        }

        if (!empty($filters['date_from'])) {
            try {
                $filters['date_from'] = Carbon::parse($filters['date_from'])->startOfDay();
            } catch (\Exception $e) {
                unset($filters['date_from']);
            }
        }
        if (!empty($filters['date_to'])) {
            try {
                $filters['date_to'] = Carbon::parse($filters['date_to'])->endOfDay();
            } catch (\Exception $e) {
                unset($filters['date_to']);
            }
        }

        // Диапазон суммы
        if ($request->has('total_price_from') && $request->query('total_price_from') !== '') {
            $filters['total_price_from'] = (float)$request->query('total_price_from');
        }
        if ($request->has('total_price_to') && $request->query('total_price_to') !== '') {
            $filters['total_price_to'] = (float)$request->query('total_price_to');
        }
        // Фильтр по наличию фото
        if ($request->has('has_photo')) {
            $filters['has_photo'] = (bool)$request->query('has_photo');
        }

        return $filters;
    }

    private function getColumnMappingFromTemplate(?ReportTemplate $template, array $defaultMapping): array
    {
        if ($template && !empty($template->columns_config)) {
            $mappedColumns = [];
            // Сортируем колонки по полю order
            $sortedColumns = collect($template->columns_config)->sortBy('order')->values();
            foreach ($sortedColumns as $column) {
                if (isset($column['header']) && isset($column['data_key'])) {
                    $mappedColumns[$column['header']] = $column['data_key'];
                }
            }
            return $mappedColumns;
        }
        return $defaultMapping; // Возвращаем маппинг по умолчанию, если шаблон не найден или пуст
    }

    /**
     * Отчет по выполненным работам.
     * @return array|StreamedResponse
     */
    public function getWorkCompletionReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['project_id', 'work_type_id', 'user_id', 'date_from', 'date_to']);
        $templateId = $request->query('template_id') ? (int)$request->query('template_id') : null;

        Log::info('Generating Work Completion Report', [
            'org_id' => $organizationId, 
            'filters' => $filters, 
            'format' => $request->query('format'),
            'template_id' => $templateId
        ]);

        $allLogsPaginator = $this->workLogRepo->getPaginatedLogs(
            $organizationId,
            $request->query('format') === 'csv' ? 100000 : $request->query('per_page', 15),
            $filters,
            $request->query('sort_by', 'completion_date'),
            $request->query('sort_direction', 'desc')
        );
        $logEntries = collect($allLogsPaginator->items())->map(function ($entry) use ($organizationId) {
            // Добавляем коэффициенты стоимости работ
            if (isset($entry->total_price)) {
                $coeff = $this->rateCoefficientService->calculateAdjustedValueDetailed(
                    $organizationId,
                    (float)$entry->total_price,
                    RateCoefficientAppliesToEnum::WORK_COSTS->value,
                    null,
                    ['project_id' => $entry->project_id, 'work_type_id' => $entry->work_type_id]
                );
                $entry->total_price_adjusted = $coeff['final'];
                $entry->coefficients_applied = $coeff['applications'];
            }
            return $entry;
        });

        if ($request->query('format') === 'csv') {
            $reportTemplate = $this->reportTemplateService->getTemplateForReport('work_completion', $request, $templateId);
            
            $defaultColumnMapping = [
                'Дата выполнения' => 'completion_date',
                'Проект' => 'project.name',
                'Вид работы' => 'workType.name',
                'Ед. изм.' => 'workType.measurementUnit.symbol',
                'Объем' => 'quantity',
                'Цена за ед.' => 'unit_price',
                'Сумма' => 'total_price',
                'Исполнитель' => 'user.name',
                'Примечание' => 'notes',
                'Дата создания записи' => 'created_at',
            ];
            $columnMapping = $this->getColumnMappingFromTemplate($reportTemplate, $defaultColumnMapping);

            if (empty($columnMapping)) {
                throw new BusinessLogicException('Не удалось определить колонки для CSV отчета. Проверьте шаблон или маппинг по умолчанию.', 400);
            }

            $exportable = $this->csvExporter->prepareDataForExport($logEntries, $columnMapping);
            $filename = $reportTemplate && $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'work_completion_report';
            return $this->csvExporter->streamDownload($filename . '_' . now()->format('d-m-Y_H-i') . '.csv', $exportable['headers'], $exportable['data']);
        }
        if ($request->query('format') === 'xlsx') {
            $reportTemplate = $this->reportTemplateService->getTemplateForReport('work_completion', $request, $templateId);
            $defaultColumnMapping = [
                'Дата выполнения' => 'completion_date',
                'Проект' => 'project.name',
                'Вид работы' => 'workType.name',
                'Ед. изм.' => 'workType.measurementUnit.symbol',
                'Объем' => 'quantity',
                'Цена за ед.' => 'unit_price',
                'Сумма' => 'total_price',
                'Исполнитель' => 'user.name',
                'Примечание' => 'notes',
                'Дата создания записи' => 'created_at',
            ];
            $columnMapping = $this->getColumnMappingFromTemplate($reportTemplate, $defaultColumnMapping);

            if (empty($columnMapping)) {
                throw new BusinessLogicException('Не удалось определить колонки для Excel отчета. Проверьте шаблон или маппинг по умолчанию.', 400);
            }

            $exportable = $this->excelExporter->prepareDataForExport($logEntries, $columnMapping);
            $filename = $reportTemplate && $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'work_completion_report';
            return $this->excelExporter->streamDownload($filename . '_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }
        
        if ($request->query('format') === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.work-completion-pdf',
                [
                    'entries' => $logEntries->toArray(),
                    'filters' => [
                        'date_from' => $filters['date_from']?->format('d.m.Y'),
                        'date_to' => $filters['date_to']?->format('d.m.Y'),
                    ],
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'work_completion_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }
        
        $aggregatedData = $this->workLogRepo->getAggregatedUsage($organizationId, $filters);
        return [
            'title' => 'Отчет по выполненным работам',
            'filters' => $filters,
            'data' => $aggregatedData,
            'pagination' => [
                'total' => $allLogsPaginator->total(),
                'per_page' => $allLogsPaginator->perPage(),
                'current_page' => $allLogsPaginator->currentPage(),
                'last_page' => $allLogsPaginator->lastPage(),
            ],
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Отчет по активности прорабов.
     */
    public function getForemanActivityReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['project_id', 'user_id', 'date_from', 'date_to']);
        $format = $request->query('format');

        Log::info('Generating Foreman Activity Report', [
            'org_id' => $organizationId, 
            'filters' => $filters,
            'format' => $format
        ]);

        $activityData = $this->userRepo->getForemanActivity($organizationId, $filters);

        // Если запрашивается Excel экспорт
        if ($format === 'xlsx') {
            if ($activityData->isEmpty()) {
                return $this->excelExporter->streamDownload(
                    'empty_foreman_report_' . now()->format('d-m-Y_H-i') . '.xlsx',
                    ['Сообщение'],
                    [['Нет данных по прорабам для указанных фильтров']]
                );
            }

            // Получаем детальные данные для Excel отчета
            $materialLogs = $this->userRepo->getForemanMaterialLogs($organizationId, $filters);
            $completedWorks = $this->userRepo->getForemanCompletedWorks($organizationId, $filters);

            $filename = 'foreman_activity_report_' . now()->format('d-m-Y_H-i') . '.xlsx';
            
            return $this->excelExporter->streamForemanActivityReport(
                $filename,
                $activityData->toArray(),
                $materialLogs->toArray(),
                $completedWorks->toArray()
            );
        }

        // Обычный JSON ответ
        return [
            'title' => 'Отчет по активности прорабов',
            'filters' => $filters,
            'data' => $activityData,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Сводный отчет по статусам проектов.
     */
    public function getProjectStatusSummaryReport(Request $request): array
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['status', 'is_archived']);

        Log::info('Generating Project Status Summary Report', ['org_id' => $organizationId, 'filters' => $filters]);

        $projectCounts = $this->projectRepo->getProjectCountsByStatus($organizationId, $filters);

        return [
            'title' => 'Сводный отчет по статусам проектов',
            'filters' => $filters,
            'data' => $projectCounts,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Официальный отчет об использовании материалов, переданных Заказчиком.
     */
    public function getOfficialMaterialUsageReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $projectId = $request->query('project_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $reportNumber = $request->query('report_number');
        $format = $request->query('format');

        // BUSINESS: Запрос официального отчета по материалам - критичный бизнес-процесс
        $this->logging->business('report.official_material_usage.requested', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'report_number' => $reportNumber,
            'format' => $format,
            'user_id' => request()->user()?->id
        ]);

        if (!$projectId || !$dateFrom || !$dateTo) {
            // TECHNICAL: Некорректные параметры для генерации отчета
            $this->logging->technical('report.official_material_usage.failed.missing_params', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'user_id' => request()->user()?->id
            ], 'warning');
            
            throw new BusinessLogicException('Необходимо указать project_id, date_from и date_to для формирования отчета.', 400);
        }

        // Подготавливаем расширенные фильтры
        $filters = [
            'material_id' => $request->query('material_id'),
            'material_name' => $request->query('material_name'),
            'operation_type' => $request->query('operation_type'),
            'supplier_id' => $request->query('supplier_id'),
            'document_number' => $request->query('document_number'),
            'work_type_id' => $request->query('work_type_id'),
            'work_description' => $request->query('work_description'),
            'user_id' => $request->query('user_id'),
            'foreman_id' => $request->query('foreman_id'),
            'invoice_date_from' => $request->query('invoice_date_from'),
            'invoice_date_to' => $request->query('invoice_date_to'),
            'min_quantity' => $request->query('min_quantity'),
            'max_quantity' => $request->query('max_quantity'),
            'min_price' => $request->query('min_price'),
            'max_price' => $request->query('max_price'),
            'has_photo' => $request->query('has_photo'),
        ];

        // Убираем пустые фильтры
        $filters = array_filter($filters, function ($value) {
            return $value !== null && $value !== '';
        });

        $reportData = $this->materialReportService->generateOfficialUsageReport(
            (int)$projectId,
            $dateFrom,
            $dateTo,
            $reportNumber ? (int)$reportNumber : null,
            $filters
        );

        // Если запрашивается Excel экспорт – генерируем файл в бакете reports и отдаём ссылку
        if ($format === 'xlsx') {
            // BUSINESS: Начало экспорта отчета в Excel
            $this->logging->business('report.official_material_usage.excel_export.started', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'date_range' => [$dateFrom, $dateTo],
                'filters_count' => count($filters),
                'user_id' => request()->user()?->id
            ]);

            $url = $this->excelExporter->uploadOfficialMaterialReport($reportData);
            if (!$url) {
                // TECHNICAL: Ошибка при экспорте Excel файла
                $this->logging->technical('report.official_material_usage.excel_export.failed', [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'user_id' => request()->user()?->id
                ], 'error');
                
                throw new BusinessLogicException('Не удалось сформировать файл отчёта.', 500);
            }

            // BUSINESS: Успешный экспорт отчета в Excel
            $this->logging->business('report.official_material_usage.excel_export.completed', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'download_url' => $url,
                'expires_at' => now()->addHours(2),
                'user_id' => request()->user()?->id
            ]);

            // AUDIT: Экспорт критичного отчета для compliance
            $this->logging->audit('report.official_material_usage.exported', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'report_number' => $reportNumber,
                'date_range' => [$dateFrom, $dateTo],
                'format' => 'xlsx',
                'performed_by' => request()->user()?->id
            ]);

            return [
                'download_url' => $url,
                'expires_at' => now()->addHours(2),
            ];
        }

        // BUSINESS: Успешная генерация JSON отчета
        $this->logging->business('report.official_material_usage.json_generated', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'date_range' => [$dateFrom, $dateTo],
            'filters_applied' => count($filters),
            'data_rows_count' => count($reportData['materials'] ?? []),
            'user_id' => request()->user()?->id
        ]);

        // AUDIT: Просмотр критичного отчета для compliance
        $this->logging->audit('report.official_material_usage.viewed', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'report_number' => $reportNumber,
            'date_range' => [$dateFrom, $dateTo],
            'format' => 'json',
            'performed_by' => request()->user()?->id
        ]);

        // JSON ответ
        return [
            'title' => 'Официальный отчет об использовании материалов',
            'data' => $reportData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Генерация отчета по остаткам на складе
     * Использует данные из BasicWarehouse через WarehouseReportDataProvider
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (warehouse_id, asset_type, category, low_stock)
     * @return array Данные отчета
     */
    public function generateWarehouseStockReport(int $organizationId, array $filters = []): array
    {
        // Получаем сервис склада
        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        
        // Получаем данные через интерфейс WarehouseReportDataProvider
        $stockData = $warehouseService->getStockData($organizationId, $filters);
        
        // BUSINESS: Генерация отчета по остаткам
        $this->logging->business('report.warehouse_stock.generated', [
            'organization_id' => $organizationId,
            'filters_applied' => count($filters),
            'data_rows_count' => count($stockData),
            'user_id' => request()->user()?->id
        ]);
        
        return [
            'title' => 'Отчет по остаткам на складе',
            'data' => $stockData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Генерация отчета по движению активов
     * Использует данные из BasicWarehouse через WarehouseReportDataProvider
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (date_from, date_to, asset_type, warehouse_id, movement_type)
     * @return array Данные отчета
     */
    public function generateWarehouseMovementsReport(int $organizationId, array $filters = []): array
    {
        // Получаем сервис склада
        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        
        // Получаем данные через интерфейс WarehouseReportDataProvider
        $movementsData = $warehouseService->getMovementsData($organizationId, $filters);
        
        // BUSINESS: Генерация отчета по движению
        $this->logging->business('report.warehouse_movements.generated', [
            'organization_id' => $organizationId,
            'filters_applied' => count($filters),
            'data_rows_count' => count($movementsData),
            'user_id' => request()->user()?->id
        ]);
        
        return [
            'title' => 'Отчет по движению активов',
            'data' => $movementsData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Генерация отчета инвентаризации
     * Использует данные из BasicWarehouse через WarehouseReportDataProvider
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (date_from, date_to, warehouse_id, status)
     * @return array Данные отчета
     */
    public function generateWarehouseInventoryReport(int $organizationId, array $filters = []): array
    {
        // Получаем сервис склада
        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        
        // Получаем данные через интерфейс WarehouseReportDataProvider
        $inventoryData = $warehouseService->getInventoryData($organizationId, $filters);
        
        // BUSINESS: Генерация отчета инвентаризации
        $this->logging->business('report.warehouse_inventory.generated', [
            'organization_id' => $organizationId,
            'filters_applied' => count($filters),
            'data_rows_count' => count($inventoryData),
            'user_id' => request()->user()?->id
        ]);
        
        return [
            'title' => 'Отчет инвентаризации',
            'data' => $inventoryData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Генерация аналитики оборачиваемости активов
     * Требует активного складского модуля с лимитами на аналитику
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (date_from, date_to, asset_ids, warehouse_id)
     * @return array Данные аналитики
     */
    public function generateWarehouseTurnoverAnalytics(int $organizationId, array $filters = []): array
    {
        // Получаем сервис склада
        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        
        // Получаем данные через интерфейс WarehouseReportDataProvider
        $turnoverData = $warehouseService->getTurnoverAnalytics($organizationId, $filters);
        
        // BUSINESS: Генерация аналитики оборачиваемости
        $this->logging->business('report.warehouse_turnover.generated', [
            'organization_id' => $organizationId,
            'filters_applied' => count($filters),
            'data_rows_count' => count($turnoverData),
            'user_id' => request()->user()?->id
        ]);
        
        return [
            'title' => 'Аналитика оборачиваемости активов',
            'data' => $turnoverData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Генерация прогноза потребности в материалах
     * Требует активного складского модуля с лимитами на прогнозирование
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (horizon_days, asset_ids)
     * @return array Данные прогноза
     */
    public function generateWarehouseForecastReport(int $organizationId, array $filters = []): array
    {
        // Получаем сервис склада
        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        
        // Получаем данные через интерфейс WarehouseReportDataProvider
        $forecastData = $warehouseService->getForecastData($organizationId, $filters);
        
        // BUSINESS: Генерация прогноза
        $this->logging->business('report.warehouse_forecast.generated', [
            'organization_id' => $organizationId,
            'filters_applied' => count($filters),
            'horizon_days' => $filters['horizon_days'] ?? 90,
            'user_id' => request()->user()?->id
        ]);
        
        return [
            'title' => 'Прогноз потребности в материалах',
            'data' => $forecastData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Генерация ABC/XYZ анализа запасов
     * Требует активного складского модуля с лимитами на аналитику
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (date_from, date_to, warehouse_id)
     * @return array Данные ABC/XYZ анализа
     */
    public function generateWarehouseAbcXyzAnalysis(int $organizationId, array $filters = []): array
    {
        // Получаем сервис склада
        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        
        // Получаем данные через интерфейс WarehouseReportDataProvider
        $abcXyzData = $warehouseService->getAbcXyzAnalysis($organizationId, $filters);
        
        // BUSINESS: Генерация ABC/XYZ анализа
        $this->logging->business('report.warehouse_abc_xyz.generated', [
            'organization_id' => $organizationId,
            'filters_applied' => count($filters),
            'data_rows_count' => count($abcXyzData),
            'user_id' => request()->user()?->id
        ]);
        
        return [
            'title' => 'ABC/XYZ анализ запасов',
            'data' => $abcXyzData,
            'filters' => $filters,
            'generated_at' => Carbon::now(),
        ];
    }

    public function getActReportsReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');

        $this->logging->business('report.act_reports.requested', [
            'organization_id' => $organizationId,
            'filters' => $request->only(['project_id', 'contractor_id', 'contract_id', 'status', 'date_from', 'date_to']),
            'user_id' => $request->user()?->id,
        ]);

        $query = DB::table('contract_performance_acts as acts')
            ->join('contracts', 'acts.contract_id', '=', 'contracts.id')
            ->leftJoin('projects as act_projects', 'acts.project_id', '=', 'act_projects.id')
            ->leftJoin('projects as contract_projects', 'contracts.project_id', '=', 'contract_projects.id')
            ->leftJoin('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->where('contracts.organization_id', $organizationId)
            ->select([
                'acts.id',
                'acts.contract_id',
                'acts.project_id',
                'acts.act_document_number',
                'acts.act_date',
                'acts.period_start',
                'acts.period_end',
                'acts.amount',
                'acts.status',
                'acts.is_approved',
                'contracts.number as contract_number',
                'contracts.subject as contract_subject',
                'contractors.id as contractor_id',
                'contractors.name as contractor_name',
                DB::raw('COALESCE(act_projects.id, contract_projects.id) as resolved_project_id'),
                DB::raw('COALESCE(act_projects.name, contract_projects.name) as project_name'),
                DB::raw('(SELECT COUNT(*) FROM performance_act_lines WHERE performance_act_lines.performance_act_id = acts.id) as lines_count'),
                DB::raw('(SELECT COUNT(*) FROM performance_act_completed_works WHERE performance_act_completed_works.performance_act_id = acts.id) as completed_works_count'),
                DB::raw('(SELECT COUNT(*) FROM files WHERE files.fileable_type = \'App\\\\Models\\\\ContractPerformanceAct\' AND files.fileable_id = acts.id) as files_count'),
            ]);

        $this->applyActReportFilters($query, $request);

        $rows = $query
            ->orderByDesc('acts.act_date')
            ->orderByDesc('acts.id')
            ->get()
            ->map(fn ($row): array => $this->mapActReportRow($row))
            ->values();

        $totals = [
            'total_acts' => $rows->count(),
            'approved_acts' => $rows->where('is_approved', true)->count(),
            'pending_acts' => $rows
                ->filter(fn (array $row): bool => !$row['is_approved'] && $row['status'] !== ContractPerformanceAct::STATUS_REJECTED)
                ->count(),
            'rejected_acts' => $rows->where('status', ContractPerformanceAct::STATUS_REJECTED)->count(),
            'signed_acts' => $rows->where('status', ContractPerformanceAct::STATUS_SIGNED)->count(),
            'total_amount' => round((float) $rows->sum('amount'), 2),
            'approved_amount' => round((float) $rows->where('is_approved', true)->sum('amount'), 2),
            'pending_amount' => round((float) $rows
                ->filter(fn (array $row): bool => !$row['is_approved'] && $row['status'] !== ContractPerformanceAct::STATUS_REJECTED)
                ->sum('amount'), 2),
            'average_amount' => $rows->count() > 0 ? round((float) $rows->avg('amount'), 2) : 0.0,
            'acts_with_files' => $rows->filter(fn (array $row): bool => $row['files_count'] > 0)->count(),
        ];

        $byStatus = $rows
            ->groupBy('status')
            ->map(fn ($items, string $status): array => [
                'status' => $status,
                'status_label' => $this->actStatusLabel($status),
                'acts_count' => $items->count(),
                'total_amount' => round((float) $items->sum('amount'), 2),
                'approved_amount' => round((float) $items->where('is_approved', true)->sum('amount'), 2),
            ])
            ->sortBy(fn (array $item): int => $this->actStatusSortWeight($item['status']))
            ->values();

        $byProjects = $rows
            ->groupBy(fn (array $row): string => (string) ($row['project_id'] ?? 0))
            ->map(fn ($items): array => [
                'project_id' => $items->first()['project_id'],
                'project' => $items->first()['project'],
                'acts_count' => $items->count(),
                'approved_acts' => $items->where('is_approved', true)->count(),
                'total_amount' => round((float) $items->sum('amount'), 2),
                'approved_amount' => round((float) $items->where('is_approved', true)->sum('amount'), 2),
            ])
            ->sortByDesc('total_amount')
            ->values();

        $byContractors = $rows
            ->groupBy(fn (array $row): string => (string) ($row['contractor_id'] ?? 0))
            ->map(fn ($items): array => [
                'contractor_id' => $items->first()['contractor_id'],
                'contractor' => $items->first()['contractor'],
                'acts_count' => $items->count(),
                'approved_acts' => $items->where('is_approved', true)->count(),
                'total_amount' => round((float) $items->sum('amount'), 2),
                'approved_amount' => round((float) $items->where('is_approved', true)->sum('amount'), 2),
            ])
            ->sortByDesc('total_amount')
            ->values();

        if ($format === 'excel') {
            $columns = [
                'Номер акта' => 'act_document_number',
                'Дата акта' => 'act_date',
                'Период с' => 'period_start',
                'Период по' => 'period_end',
                'Договор' => 'contract_number',
                'Предмет договора' => 'contract_subject',
                'Объект' => 'project',
                'Подрядчик' => 'contractor',
                'Статус' => 'status_label',
                'Сумма' => 'amount',
                'Работ в акте' => 'works_count',
                'Файлов' => 'files_count',
            ];

            $exportable = $this->excelExporter->prepareDataForExport($rows->toArray(), $columns);

            return $this->excelExporter->streamDownload(
                'act_reports_report_' . now()->format('d-m-Y_H-i') . '.xlsx',
                $exportable['headers'],
                $exportable['data']
            );
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.act-reports-pdf',
                [
                    'data' => $rows->toArray(),
                    'totals' => $totals,
                    'by_status' => $byStatus->toArray(),
                    'by_projects' => $byProjects->take(10)->toArray(),
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'act_reports_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по актам выполненных работ',
            'data' => $rows,
            'totals' => $totals,
            'by_status' => $byStatus,
            'by_projects' => $byProjects,
            'by_contractors' => $byContractors,
            'filters' => $request->only(['project_id', 'contractor_id', 'contract_id', 'status', 'date_from', 'date_to', 'search']),
            'generated_at' => Carbon::now(),
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    private function applyActReportFilters($query, Request $request): void
    {
        if ($request->filled('contract_id')) {
            $query->where('acts.contract_id', (int) $request->query('contract_id'));
        }

        if ($request->filled('contractor_id')) {
            $query->where('contracts.contractor_id', (int) $request->query('contractor_id'));
        }

        if ($request->filled('project_id')) {
            $projectId = (int) $request->query('project_id');
            $query->where(function ($builder) use ($projectId): void {
                $builder
                    ->where('acts.project_id', $projectId)
                    ->orWhere('contracts.project_id', $projectId)
                    ->orWhereExists(function ($subquery) use ($projectId): void {
                        $subquery
                            ->select(DB::raw(1))
                            ->from('contract_project')
                            ->whereColumn('contract_project.contract_id', 'contracts.id')
                            ->where('contract_project.project_id', $projectId);
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('acts.status', $request->query('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('acts.act_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('acts.act_date', '<=', $request->query('date_to'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('acts.act_document_number', 'like', "%{$search}%")
                    ->orWhere('acts.description', 'like', "%{$search}%")
                    ->orWhere('contracts.number', 'like', "%{$search}%")
                    ->orWhere('contracts.subject', 'like', "%{$search}%")
                    ->orWhere('act_projects.name', 'like', "%{$search}%")
                    ->orWhere('contract_projects.name', 'like', "%{$search}%")
                    ->orWhere('contractors.name', 'like', "%{$search}%");
            });
        }
    }

    private function mapActReportRow(object $row): array
    {
        $status = $row->status ?: ((bool) $row->is_approved ? ContractPerformanceAct::STATUS_APPROVED : ContractPerformanceAct::STATUS_DRAFT);
        $linesCount = (int) ($row->lines_count ?? 0);
        $completedWorksCount = (int) ($row->completed_works_count ?? 0);

        return [
            'id' => (int) $row->id,
            'act_document_number' => $row->act_document_number ?: 'Акт ' . $row->id,
            'act_date' => $row->act_date ? Carbon::parse($row->act_date)->toDateString() : null,
            'period_start' => $row->period_start ? Carbon::parse($row->period_start)->toDateString() : null,
            'period_end' => $row->period_end ? Carbon::parse($row->period_end)->toDateString() : null,
            'contract_id' => (int) $row->contract_id,
            'contract_number' => $row->contract_number ?: 'Договор ' . $row->contract_id,
            'contract_subject' => $row->contract_subject,
            'project_id' => $row->resolved_project_id ? (int) $row->resolved_project_id : null,
            'project' => $row->project_name ?: 'Без объекта',
            'contractor_id' => $row->contractor_id ? (int) $row->contractor_id : null,
            'contractor' => $row->contractor_name ?: 'Без подрядчика',
            'status' => $status,
            'status_label' => $this->actStatusLabel($status),
            'is_approved' => (bool) $row->is_approved,
            'amount' => round((float) $row->amount, 2),
            'works_count' => max($linesCount, $completedWorksCount),
            'files_count' => (int) ($row->files_count ?? 0),
        ];
    }

    private function actStatusLabel(string $status): string
    {
        return match ($status) {
            ContractPerformanceAct::STATUS_APPROVED => 'Утвержден',
            ContractPerformanceAct::STATUS_PENDING_APPROVAL => 'На согласовании',
            ContractPerformanceAct::STATUS_REJECTED => 'Отклонен',
            ContractPerformanceAct::STATUS_SIGNED => 'Подписан',
            ContractPerformanceAct::STATUS_DRAFT => 'Черновик',
            default => 'Без статуса',
        };
    }

    private function actStatusSortWeight(string $status): int
    {
        return match ($status) {
            ContractPerformanceAct::STATUS_APPROVED => 10,
            ContractPerformanceAct::STATUS_SIGNED => 20,
            ContractPerformanceAct::STATUS_PENDING_APPROVAL => 30,
            ContractPerformanceAct::STATUS_DRAFT => 40,
            ContractPerformanceAct::STATUS_REJECTED => 50,
            default => 60,
        };
    }

    public function getContractPaymentsReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');
        
        $this->logging->business('report.contract_payments.requested', [
            'organization_id' => $organizationId,
            'filters' => $request->only(['project_id', 'contractor_id', 'status', 'date_from', 'date_to']),
            'user_id' => $request->user()?->id
        ]);

        $query = DB::table('contracts')
            ->leftJoin('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
            ->where('contracts.organization_id', $organizationId)
            ->select(
                'contracts.id',
                'contracts.number',
                'contracts.date',
                'contracts.status',
                'contracts.total_amount',
                'contracts.start_date',
                'contracts.end_date',
                'contracts.planned_advance_amount',
                'contracts.actual_advance_amount',
                'contractors.name as contractor_name',
                'projects.name as project_name',
                // Используем новую таблицу invoices
                DB::raw('(SELECT COALESCE(SUM(paid_amount), 0) FROM payment_documents WHERE invoiceable_type = \'App\\\\Models\\\\Contract\' AND invoiceable_id = contracts.id AND payment_documents.organization_id = ' . $organizationId . ' AND deleted_at IS NULL) as paid_amount')
            );

        // Добавляем completed_amount с учетом фильтра по проекту
        $projectId = $request->filled('project_id') ? $request->query('project_id') : null;
        if ($projectId !== null) {
            $query->addSelect(DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM contract_performance_acts WHERE contract_id = contracts.id AND project_id = ' . $projectId . ' AND is_approved = true) as completed_amount'));
        } else {
            $query->addSelect(DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM contract_performance_acts WHERE contract_id = contracts.id AND is_approved = true) as completed_amount'));
        }

        if ($request->filled('project_id')) {
            $query->where(function($q) use ($projectId) {
                // Обычные контракты (project_id)
                $q->where('contracts.project_id', $projectId)
                  // ИЛИ мультипроектные контракты (через pivot таблицу)
                  ->orWhereExists(function($sub) use ($projectId) {
                      $sub->select(DB::raw(1))
                          ->from('contract_project')
                          ->whereColumn('contract_project.contract_id', 'contracts.id')
                          ->where('contract_project.project_id', $projectId);
                  });
            });
        }
        if ($request->filled('contractor_id')) {
            $query->where('contracts.contractor_id', $request->query('contractor_id'));
        }
        if ($request->filled('status')) {
            $query->where('contracts.status', $request->query('status'));
        }
        if ($request->filled('work_type_category')) {
            $query->where('contracts.work_type_category', $request->query('work_type_category'));
        }
        if ($request->filled('date_from')) {
            $query->where('contracts.date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('contracts.date', '<=', $request->query('date_to'));
        }
        if ($request->boolean('show_overdue')) {
            $query->where('contracts.end_date', '<', now())
                  ->where('contracts.status', 'active');
        }

        $contracts = $query->get()->map(function ($contract) {
            $remaining = $contract->total_amount - $contract->completed_amount;
            $debt = $contract->completed_amount - $contract->paid_amount;
            $completion_percentage = $contract->total_amount > 0 
                ? round(($contract->completed_amount / $contract->total_amount) * 100, 2) 
                : 0;
            $is_overdue = $contract->end_date && Carbon::parse($contract->end_date)->isPast() && $contract->status === 'active';
            
            return [
                'id' => $contract->id,
                'number' => $contract->number,
                'date' => $contract->date,
                'contractor' => $contract->contractor_name,
                'project' => $contract->project_name,
                'status' => $contract->status,
                'total_amount' => (float)$contract->total_amount,
                'completed_amount' => (float)$contract->completed_amount,
                'paid_amount' => (float)$contract->paid_amount,
                'remaining_amount' => (float)$remaining,
                'debt_amount' => (float)$debt,
                'completion_percentage' => $completion_percentage,
                'planned_advance' => (float)$contract->planned_advance_amount,
                'actual_advance' => (float)$contract->actual_advance_amount,
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
                'is_overdue' => $is_overdue,
            ];
        });

        if ($request->boolean('show_with_debt')) {
            $contracts = $contracts->filter(fn($c) => $c['debt_amount'] > 0);
        }

        $totals = [
            'total_contracts' => $contracts->count(),
            'total_amount' => $contracts->sum('total_amount'),
            'total_completed' => $contracts->sum('completed_amount'),
            'total_paid' => $contracts->sum('paid_amount'),
            'total_debt' => $contracts->sum('debt_amount'),
        ];

        if ($format === 'excel' || $format === 'xlsx') {
            $columns = [
                'Номер контракта' => 'number',
                'Дата' => 'date',
                'Подрядчик' => 'contractor',
                'Проект' => 'project',
                'Статус' => 'status',
                'Сумма контракта' => 'total_amount',
                'Выполнено' => 'completed_amount',
                'Оплачено' => 'paid_amount',
                'Задолженность' => 'debt_amount',
                '% выполнения' => 'completion_percentage',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($contracts->toArray(), $columns);
            return $this->excelExporter->streamDownload('contract_payments_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.contract-payments-pdf',
                [
                    'data' => $contracts->values(),
                    'totals' => $totals,
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'contract_payments_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по контрактам и платежам',
            'data' => $contracts->values(),
            'totals' => $totals,
            'filters' => $request->only(['project_id', 'contractor_id', 'status', 'date_from', 'date_to']),
            'generated_at' => Carbon::now(),
        ];
    }

    public function getContractorSettlementsReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');
        
        $this->logging->business('report.contractor_settlements.requested', [
            'organization_id' => $organizationId,
            'filters' => $request->only(['contractor_id', 'project_id', 'date_from', 'date_to']),
            'user_id' => $request->user()?->id
        ]);

        // Определяем подзапрос для completed_amount с учетом project_id
        $projectId = $request->filled('project_id') ? $request->query('project_id') : null;
        $completedAmountSubquery = $projectId !== null
            ? 'COALESCE(SUM((SELECT SUM(amount) FROM contract_performance_acts WHERE contract_id = contracts.id AND project_id = ' . $projectId . ' AND is_approved = true)), 0) as total_completed'
            : 'COALESCE(SUM((SELECT SUM(amount) FROM contract_performance_acts WHERE contract_id = contracts.id AND is_approved = true)), 0) as total_completed';
        
        $query = DB::table('contractors')
            ->where('contractors.organization_id', $organizationId)
            ->select(
                'contractors.id',
                'contractors.name',
                'contractors.inn',
                'contractors.contact_person',
                'contractors.phone',
                DB::raw('COUNT(DISTINCT contracts.id) as contracts_count'),
                DB::raw('COALESCE(SUM(contracts.total_amount), 0) as total_contract_amount'),
                DB::raw($completedAmountSubquery),
                // Используем новую таблицу invoices
                DB::raw('COALESCE(SUM((SELECT SUM(paid_amount) FROM payment_documents WHERE invoiceable_type = \'App\\\\Models\\\\Contract\' AND invoiceable_id = contracts.id AND payment_documents.organization_id = ' . $organizationId . ' AND deleted_at IS NULL)), 0) as total_paid')
            )
            ->leftJoin('contracts', 'contractors.id', '=', 'contracts.contractor_id')
            ->groupBy('contractors.id', 'contractors.name', 'contractors.inn', 'contractors.contact_person', 'contractors.phone');

        if ($request->filled('contractor_id')) {
            $query->where('contractors.id', $request->query('contractor_id'));
        }
        if ($request->filled('project_id')) {
            $query->where(function($q) use ($projectId) {
                // Обычные контракты (project_id)
                $q->where('contracts.project_id', $projectId)
                  // ИЛИ мультипроектные контракты (через pivot таблицу)
                  ->orWhereExists(function($sub) use ($projectId) {
                      $sub->select(DB::raw(1))
                          ->from('contract_project')
                          ->whereColumn('contract_project.contract_id', 'contracts.id')
                          ->where('contract_project.project_id', $projectId);
                  });
            });
        }
        if ($request->filled('date_from')) {
            $query->where('contracts.date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('contracts.date', '<=', $request->query('date_to'));
        }

        $contractors = $query->get()->map(function ($contractor) use ($organizationId) {
            $debt = $contractor->total_completed - $contractor->total_paid;
            $settlement_status = 'settled';
            
            if ($debt > 100) {
                $settlement_status = 'has_debt';
            } elseif ($debt < -100) {
                $settlement_status = 'has_prepayment';
            }

            $contractDetails = DB::table('contracts')
                ->where('contractor_id', $contractor->id)
                ->where('organization_id', $organizationId)
                ->select('id', 'number', 'date', 'total_amount', 'status')
                ->get();

            return [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'inn' => $contractor->inn,
                'contact_person' => $contractor->contact_person,
                'phone' => $contractor->phone,
                'contracts_count' => (int)$contractor->contracts_count,
                'total_contract_amount' => (float)$contractor->total_contract_amount,
                'total_completed' => (float)$contractor->total_completed,
                'total_paid' => (float)$contractor->total_paid,
                'debt_amount' => (float)$debt,
                'settlement_status' => $settlement_status,
                'contracts' => $contractDetails,
            ];
        });

        if ($request->filled('settlement_status') && $request->query('settlement_status') !== 'all') {
            $contractors = $contractors->filter(fn($c) => $c['settlement_status'] === $request->query('settlement_status'));
        }
        if ($request->filled('min_debt_amount')) {
            $contractors = $contractors->filter(fn($c) => abs($c['debt_amount']) >= $request->query('min_debt_amount'));
        }

        $totals = [
            'total_contractors' => $contractors->count(),
            'total_contracts' => $contractors->sum('contracts_count'),
            'total_contract_amount' => $contractors->sum('total_contract_amount'),
            'total_completed' => $contractors->sum('total_completed'),
            'total_paid' => $contractors->sum('total_paid'),
            'total_debt' => $contractors->sum('debt_amount'),
        ];

        if ($format === 'excel' || $format === 'xlsx') {
            $columns = [
                'Подрядчик' => 'name',
                'ИНН' => 'inn',
                'Контактное лицо' => 'contact_person',
                'Телефон' => 'phone',
                'Кол-во контрактов' => 'contracts_count',
                'Сумма контрактов' => 'total_contract_amount',
                'Выполнено работ' => 'total_completed',
                'Оплачено' => 'total_paid',
                'Задолженность' => 'debt_amount',
                'Статус расчетов' => 'settlement_status',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($contractors->toArray(), $columns);
            return $this->excelExporter->streamDownload('contractor_settlements_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.contractor-settlements-pdf',
                [
                    'data' => $contractors->values(),
                    'totals' => $totals,
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'contractor_settlements_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по расчетам с подрядчиками',
            'data' => $contractors->values(),
            'totals' => $totals,
            'filters' => $request->only(['contractor_id', 'project_id', 'date_from', 'date_to', 'settlement_status']),
            'generated_at' => Carbon::now(),
        ];
    }

    public function getWarehouseStockReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');
        
        $this->logging->business('report.warehouse_stock.requested', [
            'organization_id' => $organizationId,
            'filters' => $request->only(['warehouse_id', 'material_id', 'category', 'asset_type']),
            'user_id' => $request->user()?->id
        ]);

        $assetType = $request->filled('asset_type') ? (string) $request->query('asset_type') : null;

        $query = DB::table('warehouse_balances')
            ->join('materials', 'warehouse_balances.material_id', '=', 'materials.id')
            ->join('organization_warehouses', 'warehouse_balances.warehouse_id', '=', 'organization_warehouses.id')
            ->leftJoin('measurement_units', 'materials.measurement_unit_id', '=', 'measurement_units.id')
            ->where('warehouse_balances.organization_id', $organizationId)
            ->select(
                'warehouse_balances.id',
                'materials.name as material_name',
                'materials.code as material_code',
                'materials.category',
                'organization_warehouses.name as warehouse_name',
                'warehouse_balances.available_quantity',
                'warehouse_balances.reserved_quantity',
                'warehouse_balances.unit_price',
                'warehouse_balances.min_stock_level',
                'warehouse_balances.max_stock_level',
                'warehouse_balances.expiry_date',
                'warehouse_balances.location_code',
                'measurement_units.short_name as unit',
                DB::raw('(warehouse_balances.available_quantity + warehouse_balances.reserved_quantity) as total_quantity'),
                DB::raw('(warehouse_balances.available_quantity * warehouse_balances.unit_price) as total_value')
            );

        $this->applyWarehouseStockPresenceFilter($query, $organizationId);

        if ($assetType !== null) {
            $this->applyWarehouseStockAssetTypeFilter($query, $assetType);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_balances.warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('material_id')) {
            $query->where('warehouse_balances.material_id', $request->query('material_id'));
        }
        if ($request->filled('category')) {
            $query->where('materials.category', $request->query('category'));
        }
        if ($request->boolean('show_critical_only')) {
            $query->whereRaw('warehouse_balances.available_quantity <= warehouse_balances.min_stock_level');
        }
        if ($request->filled('min_quantity')) {
            $query->where('warehouse_balances.available_quantity', '>=', $request->query('min_quantity'));
        }
        if ($request->boolean('show_expired')) {
            $query->where('warehouse_balances.expiry_date', '<=', now());
        }
        if ($request->filled('expiring_days')) {
            $days = $request->query('expiring_days');
            $query->whereBetween('warehouse_balances.expiry_date', [now(), now()->addDays($days)]);
        }

        $stocks = $query->get()->map(function ($stock) {
            $is_critical = $stock->min_stock_level && $stock->available_quantity <= $stock->min_stock_level;
            $is_expired = $stock->expiry_date && Carbon::parse($stock->expiry_date)->isPast();
            
            return [
                'id' => $stock->id,
                'material_name' => $stock->material_name,
                'material_code' => $stock->material_code,
                'category' => $stock->category,
                'warehouse' => $stock->warehouse_name,
                'available_quantity' => (float)$stock->available_quantity,
                'reserved_quantity' => (float)$stock->reserved_quantity,
                'total_quantity' => (float)$stock->total_quantity,
                'unit' => $stock->unit,
                'unit_price' => (float)$stock->unit_price,
                'total_value' => (float)$stock->total_value,
                'min_stock_level' => (float)$stock->min_stock_level,
                'max_stock_level' => (float)$stock->max_stock_level,
                'location_code' => $stock->location_code,
                'expiry_date' => $stock->expiry_date,
                'is_critical' => $is_critical,
                'is_expired' => $is_expired,
            ];
        });

        $totals = [
            'total_items' => $stocks->count(),
            'total_quantity' => $stocks->sum('total_quantity'),
            'total_value' => $stocks->sum('total_value'),
            'critical_items' => $stocks->filter(fn($s) => $s['is_critical'])->count(),
            'expired_items' => $stocks->filter(fn($s) => $s['is_expired'])->count(),
        ];

        if ($format === 'excel' || $format === 'xlsx') {
            $columns = [
                'Материал' => 'material_name',
                'Код' => 'material_code',
                'Категория' => 'category',
                'Склад' => 'warehouse',
                'Доступно' => 'available_quantity',
                'Зарезервировано' => 'reserved_quantity',
                'Всего' => 'total_quantity',
                'Ед.изм.' => 'unit',
                'Цена за ед.' => 'unit_price',
                'Общая стоимость' => 'total_value',
                'Мин. уровень' => 'min_stock_level',
                'Критично' => 'is_critical',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($stocks->toArray(), $columns);
            return $this->excelExporter->streamDownload('warehouse_stock_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.warehouse-stock-pdf',
                [
                    'data' => $stocks->values(),
                    'totals' => $totals,
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'warehouse_stock_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по остаткам на складах',
            'data' => $stocks->values(),
            'totals' => $totals,
            'filters' => $request->only(['warehouse_id', 'material_id', 'category', 'asset_type', 'show_critical_only']),
            'generated_at' => Carbon::now(),
        ];
    }

    private function applyWarehouseStockPresenceFilter(\Illuminate\Database\Query\Builder $query, int $organizationId): void
    {
        $query->where(function (\Illuminate\Database\Query\Builder $presenceQuery) use ($organizationId): void {
            $presenceQuery
                ->whereRaw('(warehouse_balances.available_quantity + warehouse_balances.reserved_quantity) > 0')
                ->orWhereExists(function (\Illuminate\Database\Query\Builder $movementQuery) use ($organizationId): void {
                    $movementQuery
                        ->selectRaw('1')
                        ->from('warehouse_movements')
                        ->where('warehouse_movements.organization_id', $organizationId)
                        ->whereColumn('warehouse_movements.warehouse_id', 'warehouse_balances.warehouse_id')
                        ->whereColumn('warehouse_movements.material_id', 'warehouse_balances.material_id')
                        ->limit(1);
                });
        });
    }

    private function applyWarehouseStockAssetTypeFilter(\Illuminate\Database\Query\Builder $query, string $assetType): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw("COALESCE(materials.additional_properties->>'asset_type', 'material') = ?", [$assetType]);
            return;
        }

        if ($driver === 'sqlite') {
            $query->whereRaw("COALESCE(json_extract(materials.additional_properties, '$.asset_type'), 'material') = ?", [$assetType]);
            return;
        }

        $query->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(materials.additional_properties, '$.asset_type')), 'material') = ?", [$assetType]);
    }

    public function getMaterialMovementsReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');
        $dateFrom = Carbon::parse($request->query('date_from'))->startOfDay();
        $dateTo = Carbon::parse($request->query('date_to'))->endOfDay();
        
        $this->logging->business('report.material_movements.requested', [
            'organization_id' => $organizationId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'user_id' => $request->user()?->id
        ]);

        $query = DB::table('warehouse_movements')
            ->join('materials', 'warehouse_movements.material_id', '=', 'materials.id')
            ->join('organization_warehouses', 'warehouse_movements.warehouse_id', '=', 'organization_warehouses.id')
            ->leftJoin('projects', 'warehouse_movements.project_id', '=', 'projects.id')
            ->leftJoin('users', 'warehouse_movements.user_id', '=', 'users.id')
            ->leftJoin('measurement_units', 'materials.measurement_unit_id', '=', 'measurement_units.id')
            ->where('warehouse_movements.organization_id', $organizationId)
            ->whereBetween('warehouse_movements.movement_date', [$dateFrom->toDateTimeString(), $dateTo->toDateTimeString()])
            ->select(
                'warehouse_movements.id',
                'warehouse_movements.movement_date',
                'warehouse_movements.movement_type',
                'warehouse_movements.quantity',
                'warehouse_movements.price_per_unit',
                'warehouse_movements.document_number',
                'warehouse_movements.notes',
                'materials.name as material_name',
                'materials.code as material_code',
                'organization_warehouses.name as warehouse_name',
                'projects.name as project_name',
                'users.name as user_name',
                'measurement_units.short_name as unit',
                DB::raw('(warehouse_movements.quantity * warehouse_movements.price_per_unit) as total_amount')
            );

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_movements.warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('material_id')) {
            $query->where('warehouse_movements.material_id', $request->query('material_id'));
        }
        if ($request->filled('project_id')) {
            $query->where('warehouse_movements.project_id', $request->query('project_id'));
        }
        if ($request->filled('movement_type')) {
            $query->where('warehouse_movements.movement_type', $request->query('movement_type'));
        }
        if ($request->filled('user_id')) {
            $query->where('warehouse_movements.user_id', $request->query('user_id'));
        }

        $movements = $query->orderBy('warehouse_movements.movement_date', 'desc')->get()->map(function ($movement) {
            return [
                'id' => $movement->id,
                'date' => $movement->movement_date,
                'type' => $movement->movement_type,
                'material_name' => $movement->material_name,
                'material_code' => $movement->material_code,
                'warehouse' => $movement->warehouse_name,
                'project' => $movement->project_name,
                'quantity' => (float)$movement->quantity,
                'unit' => $movement->unit,
                'price_per_unit' => (float)$movement->price_per_unit,
                'total_amount' => (float)$movement->total_amount,
                'document_number' => $movement->document_number,
                'user' => $movement->user_name,
                'notes' => $movement->notes,
            ];
        });

        $totals = [
            'total_movements' => $movements->count(),
            'receipt_count' => $movements->where('type', 'receipt')->count(),
            'issue_count' => $movements->where('type', 'issue')->count(),
            'transfer_count' => $movements->where('type', 'transfer')->count(),
            'total_amount' => $movements->sum('total_amount'),
        ];

        if ($format === 'excel') {
            $columns = [
                'Дата' => 'date',
                'Тип операции' => 'type',
                'Материал' => 'material_name',
                'Код' => 'material_code',
                'Склад' => 'warehouse',
                'Проект' => 'project',
                'Количество' => 'quantity',
                'Ед.изм.' => 'unit',
                'Цена' => 'price_per_unit',
                'Сумма' => 'total_amount',
                'Документ' => 'document_number',
                'Пользователь' => 'user',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($movements->toArray(), $columns);
            return $this->excelExporter->streamDownload('material_movements_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.material-movements-pdf',
                [
                    'title' => 'Отчет по движению материалов',
                    'data' => $movements->values(),
                    'totals' => $totals,
                    'filters' => $request->only(['warehouse_id', 'material_id', 'project_id', 'movement_type', 'date_from', 'date_to']),
                    'period' => [
                        'date_from' => $dateFrom->format('d.m.Y'),
                        'date_to' => $dateTo->format('d.m.Y'),
                    ],
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'material_movements_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по движению материалов',
            'data' => $movements->values(),
            'totals' => $totals,
            'filters' => $request->only(['warehouse_id', 'material_id', 'project_id', 'movement_type', 'date_from', 'date_to']),
            'generated_at' => Carbon::now(),
        ];
    }

    public function getTimeTrackingReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');

        $dateFromInput = $request->query('date_from', $request->query('start_date'));
        $dateToInput = $request->query('date_to', $request->query('end_date'));

        try {
            $dateFrom = $dateFromInput
                ? Carbon::parse($dateFromInput)->startOfDay()
                : now()->startOfMonth();
            $dateTo = $dateToInput
                ? Carbon::parse($dateToInput)->endOfDay()
                : now()->endOfDay();
        } catch (\Throwable $e) {
            $dateFrom = now()->startOfMonth();
            $dateTo = now()->endOfDay();
        }

        $filters = $request->only([
            'user_id',
            'project_id',
            'work_type_id',
            'status',
            'worker_type',
            'worker_name',
            'is_billable',
            'billable',
            'group_by',
            'date_from',
            'date_to',
            'start_date',
            'end_date',
        ]);

        $this->logging->business('report.time_tracking.requested', [
            'organization_id' => $organizationId,
            'date_from' => $dateFrom->toDateTimeString(),
            'date_to' => $dateTo->toDateTimeString(),
            'user_id' => $request->user()?->id,
            'filters' => $filters,
        ]);

        $query = TimeEntry::query()
            ->with(['user:id,name', 'project:id,name', 'workType:id,name'])
            ->forOrganization($organizationId)
            ->forDateRange($dateFrom->toDateString(), $dateTo->toDateString())
            ->orderByDesc('work_date')
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $selectedUserId = (int) $request->query('user_id');
            $selectedUser = User::query()
                ->select(['id', 'name'])
                ->find($selectedUserId);

            $query->where(function ($userQuery) use ($selectedUserId, $selectedUser) {
                $userQuery->where('user_id', $selectedUserId);

                if ($selectedUser?->name) {
                    $userQuery->orWhere(function ($legacyUserQuery) use ($selectedUser) {
                        $legacyUserQuery
                            ->where('worker_type', 'user')
                            ->whereNull('user_id')
                            ->where('worker_name', $selectedUser->name);
                    });
                }
            });
        }

        if ($request->filled('project_id')) {
            $query->forProject((int) $request->query('project_id'));
        }

        if ($request->filled('work_type_id')) {
            $query->where('work_type_id', (int) $request->query('work_type_id'));
        }

        if ($request->filled('status')) {
            $query->byStatus((string) $request->query('status'));
        }

        if ($request->filled('worker_type')) {
            $query->forWorkerType((string) $request->query('worker_type'));
        }

        if ($request->filled('worker_name')) {
            $query->forWorkerName((string) $request->query('worker_name'));
        }

        $billableInput = $request->query('is_billable', $request->query('billable'));

        if ($billableInput !== null && $billableInput !== '') {
            $query->billable(filter_var($billableInput, FILTER_VALIDATE_BOOLEAN));
        }

        /** @var \Illuminate\Support\Collection<int, TimeEntry> $entries */
        $entries = $query->get();

        $data = $entries->map(function (TimeEntry $entry) {
            return [
                'id' => $entry->id,
                'date' => $entry->work_date?->format('Y-m-d'),
                'user' => $entry->worker_display_name,
                'type' => $entry->worker_type,
                'project' => $entry->project?->name,
                'work_type' => $entry->workType?->name,
                'title' => $entry->title,
                'hours' => (float) $entry->hours_worked,
                'hourly_rate' => (float) ($entry->hourly_rate ?? 0),
                'total_cost' => (float) $entry->total_cost,
                'status' => $entry->status,
                'is_billable' => (bool) $entry->is_billable,
            ];
        })->values();

        $groupBy = (string) $request->query('group_by', '');

        $groupedData = match ($groupBy) {
            'user' => $entries->groupBy(
                fn (TimeEntry $entry) => $entry->worker_display_name ?: 'Не указан'
            )->map(function ($group) {
                return [
                    'user' => $group->first()?->worker_display_name ?: 'Не указан',
                    'total_hours' => (float) $group->sum('hours_worked'),
                    'total_cost' => (float) $group->sum('total_cost'),
                    'entries_count' => $group->count(),
                ];
            })->values(),
            'project' => $entries->groupBy(
                fn (TimeEntry $entry) => $entry->project?->name ?: 'Без проекта'
            )->map(function ($group) {
                return [
                    'project' => $group->first()?->project?->name ?: 'Без проекта',
                    'total_hours' => (float) $group->sum('hours_worked'),
                    'total_cost' => (float) $group->sum('total_cost'),
                    'entries_count' => $group->count(),
                ];
            })->values(),
            'date' => $entries->groupBy(
                fn (TimeEntry $entry) => $entry->work_date?->format('Y-m-d') ?: 'Без даты'
            )->map(function ($group, $date) {
                return [
                    'date' => $date,
                    'total_hours' => (float) $group->sum('hours_worked'),
                    'total_cost' => (float) $group->sum('total_cost'),
                    'entries_count' => $group->count(),
                ];
            })->values(),
            'work_type' => $entries->groupBy(
                fn (TimeEntry $entry) => $entry->workType?->name ?: 'Без типа работ'
            )->map(function ($group) {
                return [
                    'work_type' => $group->first()?->workType?->name ?: 'Без типа работ',
                    'total_hours' => (float) $group->sum('hours_worked'),
                    'total_cost' => (float) $group->sum('total_cost'),
                    'entries_count' => $group->count(),
                ];
            })->values(),
            default => collect(),
        };

        $totals = [
            'total_entries' => $data->count(),
            'total_hours' => (float) $data->sum('hours'),
            'total_cost' => (float) $data->sum('total_cost'),
            'billable_hours' => (float) $data->where('is_billable', true)->sum('hours'),
            'approved_hours' => (float) $data->where('status', 'approved')->sum('hours'),
        ];

        if ($format === 'excel') {
            $columns = [
                'Дата' => 'date',
                'Сотрудник' => 'user',
                'Тип' => 'type',
                'Проект' => 'project',
                'Вид работ' => 'work_type',
                'Описание' => 'title',
                'Часов' => 'hours',
                'Ставка' => 'hourly_rate',
                'Стоимость' => 'total_cost',
                'Статус' => 'status',
                'Оплачиваемо' => 'is_billable',
            ];

            $exportable = $this->excelExporter->prepareDataForExport($data->toArray(), $columns);

            return $this->excelExporter->streamDownload(
                'time_tracking_report_' . now()->format('d-m-Y_H-i') . '.xlsx',
                $exportable['headers'],
                $exportable['data']
            );
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.time-tracking-pdf',
                [
                    'title' => 'Отчет по учету времени',
                    'data' => $data,
                    'totals' => $totals,
                    'filters' => array_merge($filters, [
                        'date_from' => $dateFrom->format('d.m.Y'),
                        'date_to' => $dateTo->format('d.m.Y'),
                    ]),
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'time_tracking_report.pdf'
            );
        }

        return [
            'title' => 'Отчет по учету рабочего времени',
            'data' => $data->toArray(),
            'grouped_data' => $groupedData->toArray(),
            'totals' => $totals,
            'filters' => $filters,
            'has_data' => $data->isNotEmpty(),
            'empty_state_message' => $data->isEmpty() ? trans_message('reports.empty') : null,
            'generated_at' => Carbon::now(),
        ];
    }

    public function getProjectProfitabilityReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');
        
        $this->logging->business('report.project_profitability.requested', [
            'organization_id' => $organizationId,
            'filters' => $request->only(['project_id', 'status', 'customer']),
            'user_id' => $request->user()?->id
        ]);

        $query = DB::table('projects')
            ->where('projects.organization_id', $organizationId)
            ->select(
                'projects.id',
                'projects.name',
                'projects.customer',
                'projects.status',
                'projects.budget_amount',
                'projects.start_date',
                'projects.end_date',
                DB::raw('(SELECT COALESCE(SUM(total_amount), 0) FROM contracts WHERE project_id = projects.id AND contracts.organization_id = ' . $organizationId . ') as contractor_costs'),
                DB::raw('(SELECT COALESCE(SUM(quantity * price), 0) FROM warehouse_movements WHERE project_id = projects.id AND warehouse_movements.organization_id = ' . $organizationId . ' AND movement_type = \'receipt\') as material_costs')
            );

        if ($request->filled('project_id')) {
            $query->where('projects.id', $request->query('project_id'));
        }
        if ($request->filled('status')) {
            $query->where('projects.status', $request->query('status'));
        }
        if ($request->filled('customer')) {
            $query->where('projects.customer', 'like', '%' . $request->query('customer') . '%');
        }
        if ($request->filled('date_from')) {
            $query->where('projects.start_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('projects.start_date', '<=', $request->query('date_to'));
        }

        $projects = $query->get()->map(function ($project) use ($request, $organizationId) {
            $income = (float)$project->budget_amount;
            $contractorCosts = (float)$project->contractor_costs;
            $materialCosts = (float)$project->material_costs;
            $laborCosts = 0;

            if ($request->boolean('include_labor_costs')) {
                $laborCosts = (float)DB::table('time_entries')
                    ->where('project_id', $project->id)
                    ->where('organization_id', $organizationId)
                    ->where('is_billable', true)
                    ->sum(DB::raw('hours_worked * COALESCE(hourly_rate, 0)'));
            }

            $totalExpenses = $contractorCosts + $materialCosts + $laborCosts;
            $profit = $income - $totalExpenses;
            $profitability = $income > 0 ? round(($profit / $income) * 100, 2) : 0;

            return [
                'id' => $project->id,
                'name' => $project->name,
                'customer' => $project->customer,
                'status' => $project->status,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'income' => $income,
                'contractor_costs' => $contractorCosts,
                'material_costs' => $materialCosts,
                'labor_costs' => $laborCosts,
                'total_expenses' => $totalExpenses,
                'profit' => $profit,
                'profitability_percent' => $profitability,
            ];
        });

        if ($request->filled('min_profitability')) {
            $projects = $projects->filter(fn($p) => $p['profitability_percent'] >= $request->query('min_profitability'));
        }
        if ($request->filled('max_profitability')) {
            $projects = $projects->filter(fn($p) => $p['profitability_percent'] <= $request->query('max_profitability'));
        }
        if ($request->boolean('show_losses_only')) {
            $projects = $projects->filter(fn($p) => $p['profit'] < 0);
        }

        $totals = [
            'total_projects' => $projects->count(),
            'total_income' => $projects->sum('income'),
            'total_expenses' => $projects->sum('total_expenses'),
            'total_profit' => $projects->sum('profit'),
            'avg_profitability' => $projects->count() > 0 ? round($projects->avg('profitability_percent'), 2) : 0,
            'profitable_projects' => $projects->filter(fn($p) => $p['profit'] > 0)->count(),
            'loss_making_projects' => $projects->filter(fn($p) => $p['profit'] < 0)->count(),
        ];

        if ($format === 'excel' || $format === 'xlsx') {
            $columns = [
                'Проект' => 'name',
                'Заказчик' => 'customer',
                'Статус' => 'status',
                'Доход' => 'income',
                'Подрядчики' => 'contractor_costs',
                'Материалы' => 'material_costs',
                'Зарплата' => 'labor_costs',
                'Всего расходов' => 'total_expenses',
                'Прибыль' => 'profit',
                'Рентабельность %' => 'profitability_percent',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($projects->toArray(), $columns);
            return $this->excelExporter->streamDownload('project_profitability_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.project-profitability-pdf',
                [
                    'data' => $projects->values(),
                    'totals' => $totals,
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'project_profitability_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по рентабельности проектов',
            'data' => $projects->values(),
            'totals' => $totals,
            'filters' => $request->only(['project_id', 'status', 'customer', 'date_from', 'date_to']),
            'generated_at' => Carbon::now(),
        ];
    }

    public function getProjectTimelinesReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $format = $request->query('format', 'json');
        
        $this->logging->business('report.project_timelines.requested', [
            'organization_id' => $organizationId,
            'filters' => $request->only(['project_id', 'status', 'customer']),
            'user_id' => $request->user()?->id
        ]);

        $query = DB::table('projects')
            ->where('projects.organization_id', $organizationId)
            ->select(
                'projects.id',
                'projects.name',
                'projects.customer',
                'projects.status',
                'projects.start_date',
                'projects.end_date',
                'projects.budget_amount',
                'projects.created_at',
                DB::raw('(SELECT COALESCE(SUM(total_amount), 0) FROM contracts WHERE project_id = projects.id AND contracts.organization_id = ' . $organizationId . ') as total_contract_amount'),
                // Фильтруем акты по project_id для корректного отображения в мультипроектных контрактах
                DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM contract_performance_acts WHERE project_id = projects.id AND is_approved = true) as completed_amount')
            );

        if ($request->filled('project_id')) {
            $query->where('projects.id', $request->query('project_id'));
        }
        if ($request->filled('status')) {
            $query->where('projects.status', $request->query('status'));
        }
        if ($request->filled('customer')) {
            $query->where('projects.customer', 'like', '%' . $request->query('customer') . '%');
        }
        if ($request->filled('date_from')) {
            $query->where('projects.start_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('projects.start_date', '<=', $request->query('date_to'));
        }

        $projects = $query->get()->map(function ($project) {
            $startDate = $project->start_date ? Carbon::parse($project->start_date) : null;
            $endDate = $project->end_date ? Carbon::parse($project->end_date) : null;
            $now = Carbon::now();
            
            $totalDays = $startDate && $endDate ? $startDate->diffInDays($endDate) : 0;
            $elapsedDays = $startDate ? $startDate->diffInDays($now) : 0;
            $remainingDays = $endDate ? $now->diffInDays($endDate, false) : 0;
            
            $isOverdue = $endDate && $endDate->isPast() && $project->status === 'active';
            $delayDays = $isOverdue ? abs($remainingDays) : 0;
            
            $completionPercent = $project->budget_amount > 0 
                ? round(($project->completed_amount / $project->budget_amount) * 100, 2) 
                : 0;
            
            $timeProgress = $totalDays > 0 ? round(($elapsedDays / $totalDays) * 100, 2) : 0;
            
            $isAtRisk = $timeProgress > $completionPercent + 20 && $project->status === 'active';
            
            $estimatedCompletion = null;
            if ($completionPercent > 0 && $completionPercent < 100 && $startDate) {
                $daysToComplete = ($elapsedDays / $completionPercent) * 100;
                $estimatedCompletion = $startDate->copy()->addDays($daysToComplete)->toDateString();
            }

            return [
                'id' => $project->id,
                'name' => $project->name,
                'customer' => $project->customer,
                'status' => $project->status,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'planned_duration_days' => (int)$totalDays,
                'elapsed_days' => (int)$elapsedDays,
                'remaining_days' => (int)$remainingDays,
                'is_overdue' => $isOverdue,
                'delay_days' => (int)$delayDays,
                'is_at_risk' => $isAtRisk,
                'completion_percent' => $completionPercent,
                'time_progress_percent' => $timeProgress,
                'estimated_completion_date' => $estimatedCompletion,
            ];
        });

        if ($request->boolean('show_overdue_only')) {
            $projects = $projects->filter(fn($p) => $p['is_overdue']);
        }
        if ($request->boolean('show_at_risk')) {
            $projects = $projects->filter(fn($p) => $p['is_at_risk'] || $p['is_overdue']);
        }
        if ($request->filled('min_delay_days')) {
            $projects = $projects->filter(fn($p) => $p['delay_days'] >= $request->query('min_delay_days'));
        }

        $totals = [
            'total_projects' => $projects->count(),
            'active_projects' => $projects->where('status', 'active')->count(),
            'overdue_projects' => $projects->filter(fn($p) => $p['is_overdue'])->count(),
            'at_risk_projects' => $projects->filter(fn($p) => $p['is_at_risk'])->count(),
            'avg_completion_percent' => $projects->count() > 0 ? round($projects->avg('completion_percent'), 2) : 0,
        ];

        if ($format === 'excel') {
            $columns = [
                'Проект' => 'name',
                'Заказчик' => 'customer',
                'Статус' => 'status',
                'Дата начала' => 'start_date',
                'Дата окончания' => 'end_date',
                'План дней' => 'planned_duration_days',
                'Прошло дней' => 'elapsed_days',
                'Осталось дней' => 'remaining_days',
                'Просрочено' => 'is_overdue',
                'Задержка дней' => 'delay_days',
                '% выполнения' => 'completion_percent',
                'В зоне риска' => 'is_at_risk',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($projects->toArray(), $columns);
            return $this->excelExporter->streamDownload('project_timelines_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            return $this->pdfExporter->streamDownload(
                'reports.project-timelines-pdf',
                [
                    'title' => 'Отчет по срокам выполнения проектов',
                    'data' => $projects->values(),
                    'totals' => $totals,
                    'filters' => $request->only(['project_id', 'status', 'customer', 'date_from', 'date_to']),
                    'generated_at' => Carbon::now()->format('d.m.Y H:i'),
                ],
                'project_timelines_report_' . now()->format('d-m-Y_H-i') . '.pdf',
                'a4',
                'landscape'
            );
        }

        return [
            'title' => 'Отчет по срокам выполнения проектов',
            'data' => $projects->values(),
            'totals' => $totals,
            'filters' => $request->only(['project_id', 'status', 'customer', 'date_from', 'date_to']),
            'generated_at' => Carbon::now(),
        ];
    }

} 
