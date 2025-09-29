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
                    return $this->csvExporter->streamDownload('empty_report_'.date('YmdHis').'.csv', ['Сообщение'], [['Данные по указанным фильтрам отсутствуют.']]);
                }
                if ($format === 'xlsx') {
                    return $this->excelExporter->streamDownload('empty_report_'.date('YmdHis').'.xlsx', ['Сообщение'], [['Данные по указанным фильтрам отсутствуют.']]);
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
                return $this->csvExporter->streamDownload($filename . '_' . date('YmdHis') . '.csv', $exportable['headers'], $exportable['data']);
            }
            if ($format === 'xlsx') {
                $exportable = $this->excelExporter->prepareDataForExport($dataForExport, $columnMapping);
                $filename = $reportTemplate && $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'material_usage_report';
                return $this->excelExporter->streamDownload($filename . '_' . date('YmdHis') . '.xlsx', $exportable['headers'], $exportable['data']);
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
            return $this->csvExporter->streamDownload($filename . '_' . date('YmdHis'), $exportable['headers'], $exportable['data']);
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
            return $this->excelExporter->streamDownload($filename . '_' . date('YmdHis') . '.xlsx', $exportable['headers'], $exportable['data']);
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
                    'empty_foreman_report_' . date('YmdHis') . '.xlsx',
                    ['Сообщение'],
                    [['Нет данных по прорабам для указанных фильтров']]
                );
            }

            // Получаем детальные данные для Excel отчета
            $materialLogs = $this->userRepo->getForemanMaterialLogs($organizationId, $filters);
            $completedWorks = $this->userRepo->getForemanCompletedWorks($organizationId, $filters);

            $filename = 'foreman_activity_report_' . date('YmdHis') . '.xlsx';
            
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
} 