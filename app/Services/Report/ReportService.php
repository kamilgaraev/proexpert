<?php

namespace App\Services\Report;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\Export\CsvExporterService;
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

class ReportService
{
    protected MaterialUsageLogRepositoryInterface $materialLogRepo;
    protected WorkCompletionLogRepositoryInterface $workLogRepo;
    protected ProjectRepositoryInterface $projectRepo;
    protected UserRepositoryInterface $userRepo;
    protected CsvExporterService $csvExporter;
    protected ReportTemplateService $reportTemplateService;

    public function __construct(
        MaterialUsageLogRepositoryInterface $materialLogRepo,
        WorkCompletionLogRepositoryInterface $workLogRepo,
        ProjectRepositoryInterface $projectRepo,
        UserRepositoryInterface $userRepo,
        CsvExporterService $csvExporter,
        ReportTemplateService $reportTemplateService
    ) {
        $this->materialLogRepo = $materialLogRepo;
        $this->workLogRepo = $workLogRepo;
        $this->projectRepo = $projectRepo;
        $this->userRepo = $userRepo;
        $this->csvExporter = $csvExporter;
        $this->reportTemplateService = $reportTemplateService;
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
        $filters = $this->prepareReportFilters($request, ['project_id', 'material_id', 'user_id', 'date_from', 'date_to', 'operation_type']);
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
                // Вернуть пустой файл или HTTP ошибку/сообщение
                // Для примера, вернем пустой CSV, чтобы не было ошибки 500
                if ($format === 'csv') {
                    return $this->csvExporter->streamDownload('empty_report_'.date('YmdHis').'.csv', ['Сообщение'], [['Данные по указанным фильтрам отсутствуют.']]);
                }
                // Для xlsx можно аналогично вернуть пустой Excel или ошибку
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

            $dataForExport = \App\Http\Resources\Api\V1\Admin\Log\MaterialUsageLogResource::collection($logEntriesModels)->resolve($request); // Передаем $request в resolve(), если ресурс его использует

            Log::debug('[ReportService] Data prepared for file export (after resource transformation):', [
                'count' => count($dataForExport),
                'first_entry_example' => !empty($dataForExport) ? $dataForExport[0] : null,
            ]);

            $exportable = $this->csvExporter->prepareDataForExport($dataForExport, $columnMapping);
            $filename = $reportTemplate && $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'material_usage_report';
            return $this->csvExporter->streamDownload($filename . '_' . date('YmdHis') . '.' . $format, $exportable['headers'], $exportable['data']);
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
        $logEntries = collect($allLogsPaginator->items());

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
    public function getForemanActivityReport(Request $request): array
    {
        $organizationId = $this->getCurrentOrgId($request);
        $filters = $this->prepareReportFilters($request, ['project_id', 'user_id', 'date_from', 'date_to']);

        Log::info('Generating Foreman Activity Report', ['org_id' => $organizationId, 'filters' => $filters]);

        $activityData = $this->userRepo->getForemanActivity($organizationId, $filters);

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
} 