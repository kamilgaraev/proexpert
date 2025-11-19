<?php

namespace App\Services\Report;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\Export\CsvExporterService;
use App\Services\Export\ExcelExporterService;
use App\Services\Logging\LoggingService;
use App\Models\User;
use App\Models\Role;
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

class ReportService
{
    protected MaterialUsageLogRepositoryInterface $materialLogRepo;
    protected WorkCompletionLogRepositoryInterface $workLogRepo;
    protected ProjectRepositoryInterface $projectRepo;
    protected UserRepositoryInterface $userRepo;
    protected CsvExporterService $csvExporter;
    protected ExcelExporterService $excelExporter;
    protected ReportTemplateService $reportTemplateService;
    protected MaterialReportService $materialReportService;
    protected RateCoefficientService $rateCoefficientService;
    protected LoggingService $logging;

    public function __construct(
        MaterialUsageLogRepositoryInterface $materialLogRepo,
        WorkCompletionLogRepositoryInterface $workLogRepo,
        ProjectRepositoryInterface $projectRepo,
        UserRepositoryInterface $userRepo,
        CsvExporterService $csvExporter,
        ExcelExporterService $excelExporter,
        ReportTemplateService $reportTemplateService,
        MaterialReportService $materialReportService,
        RateCoefficientService $rateCoefficientService,
        LoggingService $logging
    ) {
        $this->materialLogRepo = $materialLogRepo;
        $this->workLogRepo = $workLogRepo;
        $this->projectRepo = $projectRepo;
        $this->userRepo = $userRepo;
        $this->csvExporter = $csvExporter;
        $this->excelExporter = $excelExporter;
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
     * Отчет по расходу материалов.
     * @return array|StreamedResponse
     */
    public function getMaterialUsageReport(Request $request): array | StreamedResponse
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, [
            'project_id',
            'material_id',
            'user_id',
            'supplier_id',
            'work_type_id',
            'date_from',
            'date_to',
            'operation_type',
        ]);
        $templateId = $request->query('template_id') ? (int)$request->query('template_id') : null;
        $format = $request->query('format');

        Log::info('Generating Material Usage Report', [
            'org_id' => $organizationId, 
            'filters' => $filters, 
            'format' => $format,
            'template_id' => $templateId
        ]);

        $isExport = ($format === 'csv' || $format === 'xlsx');
        
        // Сначала получим общее количество для экспорта, если это необходимо для установки perPage
        // Это может потребовать дополнительного запроса count(*), если не хотим грузить все в память сразу
        // Для простоты пока оставим получение всех записей через большой perPage для экспорта
        // Оптимальнее было бы сделать отдельный метод в репозитории для получения всех отфильтрованных записей без пагинации
        $perPageForPaginator = $isExport ? 100000 : $request->query('per_page', 15); // Большое число для "всех" записей при экспорте

        $allLogsPaginator = $this->materialLogRepo->getPaginatedLogs(
            $organizationId,
            $perPageForPaginator,
            $filters,
            $request->query('sort_by', 'usage_date'),
            $request->query('sort_direction', 'desc')
        );
        
        $logEntriesModels = collect($allLogsPaginator->items());

        Log::debug('[ReportService] Data for potential export/display:', [
            'filters_used' => $filters,
            'log_entries_count' => $logEntriesModels->count(),
            'retrieved_for_pagination_total' => $allLogsPaginator->total(), // Общее количество найденное пагинатором
            'first_log_entry_model_example' => $logEntriesModels->first() ?? null 
        ]);

        if ($isExport) {
            if ($logEntriesModels->isEmpty()) {
                Log::warning('[ReportService] No log entries found for file export with current filters.');
                if ($format === 'csv') {
                    return $this->csvExporter->streamDownload('empty_report_' . now()->format('d-m-Y_H-i') . '.csv', ['Сообщение'], [['Данные по указанным фильтрам отсутствуют.']]);
                }
                if ($format === 'xlsx') {
                    return $this->excelExporter->streamDownload('empty_report_' . now()->format('d-m-Y_H-i') . '.xlsx', ['Сообщение'], [['Данные по указанным фильтрам отсутствуют.']]);
                }
                throw new BusinessLogicException('Нет данных для экспорта по указанным фильтрам.', 404);
            }

            $reportTemplate = $this->reportTemplateService->getTemplateForReport('material_usage', $request, $templateId);
            
            $defaultColumnMapping = [
                'ID' => 'id',
                'Дата операции' => 'usage_date',
                'Проект' => 'project_name',
                'Материал' => 'material_name',
                'Ед. изм.' => 'unit_symbol',
                'Тип операции' => 'operation_type_readable', 
                'Количество' => 'quantity',
                'Цена за ед.' => 'unit_price',
                'Сумма' => 'total_price',
                'Поставщик' => 'supplier_name',
                'Документ №' => 'document_number',
                'Дата накладной' => 'invoice_date',
                'Вид работ (списание)' => 'work_type_name',
                'Исполнитель' => 'user_name',
                'Примечание' => 'notes',
                'Дата создания записи' => 'created_at',
            ];
            
            $columnMapping = $this->getColumnMappingFromTemplate($reportTemplate, $defaultColumnMapping);
            Log::debug('[ReportService] Column mapping for file export:', ['column_mapping_used' => $columnMapping]);

            if (empty($columnMapping)) {
                throw new BusinessLogicException('Не удалось определить колонки для отчета. Проверьте шаблон или маппинг по умолчанию.', 400);
            }

            $dataForExport = \App\Http\Resources\Api\V1\Admin\Log\MaterialUsageLogResource::collection($logEntriesModels)->resolve($request);

            Log::debug('[ReportService] Data TRULY PREPARED for Csv/ExcelExporterService:', [
                'data_for_export_count' => count($dataForExport),
                'first_data_for_export_example' => !empty($dataForExport) ? ($dataForExport[0] ?? null) : null,
            ]);

            if ($format === 'csv') {
                $exportable = $this->csvExporter->prepareDataForExport($dataForExport, $columnMapping);
                $filename = $reportTemplate && $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'material_usage_report';
                return $this->csvExporter->streamDownload($filename . '_' . now()->format('d-m-Y_H-i') . '.csv', $exportable['headers'], $exportable['data']);
            }
            if ($format === 'xlsx') {
                $exportable = $this->excelExporter->prepareDataForExport($dataForExport, $columnMapping);
                $filename = $reportTemplate && $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'material_usage_report';
                return $this->excelExporter->streamDownload($filename . '_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
            }
        }

        // Логика для JSON ответа (использует $aggregatedData)
        $aggregatedData = $this->materialLogRepo->getAggregatedUsage($organizationId, $filters);
        return [
            'title' => 'Отчет по расходу материалов',
            'filters' => $filters,
            'data' => $aggregatedData, // Для JSON по-прежнему агрегированные данные
            'pagination' => [
                'total' => $allLogsPaginator->total(),       // Общее количество сырых логов
                'per_page' => $allLogsPaginator->perPage(),   // Сколько на странице для JSON (если бы он был пагинированным)
                'current_page' => $allLogsPaginator->currentPage(),
                'last_page' => $allLogsPaginator->lastPage(),
            ],
            'generated_at' => Carbon::now(),
        ];
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
     * Только для AdvancedWarehouse + AdvancedReports
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (date_from, date_to, asset_ids, warehouse_id)
     * @return array Данные аналитики
     */
    public function generateWarehouseTurnoverAnalytics(int $organizationId, array $filters = []): array
    {
        // Получаем сервис продвинутого склада (будет создан в следующих этапах)
        // Пока используем BasicWarehouse который возвращает заглушку
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
     * Только для AdvancedWarehouse + AdvancedReports
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (horizon_days, asset_ids)
     * @return array Данные прогноза
     */
    public function generateWarehouseForecastReport(int $organizationId, array $filters = []): array
    {
        // Получаем сервис продвинутого склада (будет создан в следующих этапах)
        // Пока используем BasicWarehouse который возвращает заглушку
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
     * Только для AdvancedWarehouse + AdvancedReports
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (date_from, date_to, warehouse_id)
     * @return array Данные ABC/XYZ анализа
     */
    public function generateWarehouseAbcXyzAnalysis(int $organizationId, array $filters = []): array
    {
        // Получаем сервис продвинутого склада (будет создан в следующих этапах)
        // Пока используем BasicWarehouse который возвращает заглушку
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
                DB::raw('(SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE invoiceable_type = \'App\\\\Models\\\\Contract\' AND invoiceable_id = contracts.id AND deleted_at IS NULL) as paid_amount'),
                DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM contract_performance_acts WHERE contract_id = contracts.id AND is_approved = true) as completed_amount')
            );

        if ($request->filled('project_id')) {
            $query->where('contracts.project_id', $request->query('project_id'));
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

        if ($format === 'excel') {
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
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
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
                DB::raw('COALESCE(SUM((SELECT SUM(amount) FROM contract_performance_acts WHERE contract_id = contracts.id AND is_approved = true)), 0) as total_completed'),
                // Используем новую таблицу invoices
                DB::raw('COALESCE(SUM((SELECT SUM(paid_amount) FROM invoices WHERE invoiceable_type = \'App\\\\Models\\\\Contract\' AND invoiceable_id = contracts.id AND deleted_at IS NULL)), 0) as total_paid')
            )
            ->leftJoin('contracts', 'contractors.id', '=', 'contracts.contractor_id')
            ->groupBy('contractors.id', 'contractors.name', 'contractors.inn', 'contractors.contact_person', 'contractors.phone');

        if ($request->filled('contractor_id')) {
            $query->where('contractors.id', $request->query('contractor_id'));
        }
        if ($request->filled('project_id')) {
            $query->where('contracts.project_id', $request->query('project_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('contracts.date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('contracts.date', '<=', $request->query('date_to'));
        }

        $contractors = $query->get()->map(function ($contractor) use ($request, $organizationId) {
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

        if ($format === 'excel') {
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
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
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
            'filters' => $request->only(['warehouse_id', 'material_id', 'category']),
            'user_id' => $request->user()?->id
        ]);

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
                'warehouse_balances.average_price',
                'warehouse_balances.min_stock_level',
                'warehouse_balances.max_stock_level',
                'warehouse_balances.expiry_date',
                'warehouse_balances.location_code',
                'measurement_units.short_name as unit',
                DB::raw('(warehouse_balances.available_quantity + warehouse_balances.reserved_quantity) as total_quantity'),
                DB::raw('(warehouse_balances.available_quantity * warehouse_balances.average_price) as total_value')
            );

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
                'average_price' => (float)$stock->average_price,
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

        if ($format === 'excel') {
            $columns = [
                'Материал' => 'material_name',
                'Код' => 'material_code',
                'Категория' => 'category',
                'Склад' => 'warehouse',
                'Доступно' => 'available_quantity',
                'Зарезервировано' => 'reserved_quantity',
                'Всего' => 'total_quantity',
                'Ед.изм.' => 'unit',
                'Средняя цена' => 'average_price',
                'Общая стоимость' => 'total_value',
                'Мин. уровень' => 'min_stock_level',
                'Критично' => 'is_critical',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($stocks->toArray(), $columns);
            return $this->excelExporter->streamDownload('warehouse_stock_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
        }

        return [
            'title' => 'Отчет по остаткам на складах',
            'data' => $stocks->values(),
            'totals' => $totals,
            'filters' => $request->only(['warehouse_id', 'material_id', 'category', 'show_critical_only']),
            'generated_at' => Carbon::now(),
        ];
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
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
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
        $dateFrom = Carbon::parse($request->query('date_from'))->startOfDay();
        $dateTo = Carbon::parse($request->query('date_to'))->endOfDay();
        
        $this->logging->business('report.time_tracking.requested', [
            'organization_id' => $organizationId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'user_id' => $request->user()?->id
        ]);

        $query = DB::table('time_entries')
            ->join('users', 'time_entries.user_id', '=', 'users.id')
            ->leftJoin('projects', 'time_entries.project_id', '=', 'projects.id')
            ->leftJoin('work_types', 'time_entries.work_type_id', '=', 'work_types.id')
            ->where('time_entries.organization_id', $organizationId)
            ->whereBetween('time_entries.work_date', [$dateFrom->toDateTimeString(), $dateTo->toDateTimeString()])
            ->select(
                'time_entries.id',
                'time_entries.work_date',
                'time_entries.hours_worked',
                'time_entries.status',
                'time_entries.is_billable',
                'time_entries.hourly_rate',
                'time_entries.title',
                'users.name as user_name',
                'projects.name as project_name',
                'work_types.name as work_type_name',
                DB::raw('(time_entries.hours_worked * COALESCE(time_entries.hourly_rate, 0)) as total_cost')
            );

        if ($request->filled('user_id')) {
            $query->where('time_entries.user_id', $request->query('user_id'));
        }
        if ($request->filled('project_id')) {
            $query->where('time_entries.project_id', $request->query('project_id'));
        }
        if ($request->filled('work_type_id')) {
            $query->where('time_entries.work_type_id', $request->query('work_type_id'));
        }
        if ($request->filled('status')) {
            $query->where('time_entries.status', $request->query('status'));
        }
        if ($request->has('is_billable')) {
            $query->where('time_entries.is_billable', $request->boolean('is_billable'));
        }

        $entries = $query->orderBy('time_entries.work_date', 'desc')->get();

        $groupBy = $request->query('group_by');
        $grouped = null;

        if ($groupBy === 'user') {
            $grouped = $entries->groupBy('user_name')->map(function ($group) {
                return [
                    'user' => $group->first()->user_name,
                    'total_hours' => $group->sum('hours_worked'),
                    'total_cost' => $group->sum('total_cost'),
                    'entries_count' => $group->count(),
                ];
            })->values();
        } elseif ($groupBy === 'project') {
            $grouped = $entries->groupBy('project_name')->map(function ($group) {
                return [
                    'project' => $group->first()->project_name ?? 'Без проекта',
                    'total_hours' => $group->sum('hours_worked'),
                    'total_cost' => $group->sum('total_cost'),
                    'entries_count' => $group->count(),
                ];
            })->values();
        }

        $data = $entries->map(function ($entry) {
            return [
                'id' => $entry->id,
                'date' => $entry->work_date,
                'user' => $entry->user_name,
                'project' => $entry->project_name,
                'work_type' => $entry->work_type_name,
                'title' => $entry->title,
                'hours' => (float)$entry->hours_worked,
                'hourly_rate' => (float)$entry->hourly_rate,
                'total_cost' => (float)$entry->total_cost,
                'status' => $entry->status,
                'is_billable' => (bool)$entry->is_billable,
            ];
        });

        $totals = [
            'total_entries' => $data->count(),
            'total_hours' => $data->sum('hours'),
            'total_cost' => $data->sum('total_cost'),
            'billable_hours' => $data->where('is_billable', true)->sum('hours'),
            'approved_hours' => $data->where('status', 'approved')->sum('hours'),
        ];

        if ($format === 'excel') {
            $columns = [
                'Дата' => 'date',
                'Сотрудник' => 'user',
                'Проект' => 'project',
                'Тип работ' => 'work_type',
                'Описание' => 'title',
                'Часов' => 'hours',
                'Ставка' => 'hourly_rate',
                'Стоимость' => 'total_cost',
                'Статус' => 'status',
                'Оплачиваемо' => 'is_billable',
            ];
            $exportable = $this->excelExporter->prepareDataForExport($data->toArray(), $columns);
            return $this->excelExporter->streamDownload('time_tracking_report_' . now()->format('d-m-Y_H-i') . '.xlsx', $exportable['headers'], $exportable['data']);
        }

        if ($format === 'pdf') {
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
        }

        return [
            'title' => 'Отчет по учету рабочего времени',
            'data' => $data->values(),
            'grouped_data' => $grouped,
            'totals' => $totals,
            'filters' => $request->only(['user_id', 'project_id', 'work_type_id', 'status', 'date_from', 'date_to']),
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
                DB::raw('(SELECT COALESCE(SUM(total_amount), 0) FROM contracts WHERE project_id = projects.id) as contractor_costs'),
                DB::raw('(SELECT COALESCE(SUM(total_price), 0) FROM material_receipts WHERE project_id = projects.id) as material_costs')
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

        $projects = $query->get()->map(function ($project) use ($request) {
            $income = (float)$project->budget_amount;
            $contractorCosts = (float)$project->contractor_costs;
            $materialCosts = (float)$project->material_costs;
            $laborCosts = 0;

            if ($request->boolean('include_labor_costs')) {
                $laborCosts = (float)DB::table('time_entries')
                    ->where('project_id', $project->id)
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

        if ($format === 'excel') {
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
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
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
                DB::raw('(SELECT COALESCE(SUM(total_amount), 0) FROM contracts WHERE project_id = projects.id) as total_contract_amount'),
                DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM contract_performance_acts WHERE contract_id IN (SELECT id FROM contracts WHERE project_id = projects.id) AND is_approved = true) as completed_amount')
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
            throw new BusinessLogicException('PDF экспорт для этого отчета пока не реализован. Используйте Excel.', 501);
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