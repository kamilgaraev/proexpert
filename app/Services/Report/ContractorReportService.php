<?php

namespace App\Services\Report;

use App\Http\Requests\Api\V1\Admin\ContractorReportRequest;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use App\Models\Contractor;
use App\BusinessModules\Core\Payments\Models\Invoice;
// ContractPayment больше не используется - платежи теперь в модуле Payments (invoices)
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\Organization;
use App\Services\Export\CsvExporterService;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use App\Services\Organization\OrganizationContext;
use App\Services\Report\ReportTemplateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

class ContractorReportService
{
    protected CsvExporterService $csvExporter;

    protected ExcelExporterService $excelExporter;

    protected ReportTemplateService $templateService;

    public function __construct(
        CsvExporterService $csvExporter,
        ExcelExporterService $excelExporter,
        ReportTemplateService $templateService
    ) {
        $this->csvExporter = $csvExporter;
        $this->excelExporter = $excelExporter;
        $this->templateService = $templateService;
    }

    /**
     * Получить сводный отчет по подрядчикам для проекта.
     */
    public function getContractorSummaryReport(ContractorReportRequest $request): array|StreamedResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->validated('project_id');
        $dateFrom = $request->validated('date_from');
        $dateTo = $request->validated('date_to');
        $contractorIds = $request->validated('contractor_ids');
        $contractStatus = $request->validated('contract_status');
        $includeCompletedWorks = $request->validated('include_completed_works');
        $includePayments = $request->validated('include_payments');
        $includeMaterials = $request->validated('include_materials');
        $exportFormat = $request->validated('export_format');
        $sortBy = $request->validated('sort_by');
        $sortDirection = $request->validated('sort_direction');

        // Получаем информацию о проекте
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Базовый запрос для получения подрядчиков с контрактами по проекту
        $contractorsQuery = Contractor::select([
            'contractors.id',
            'contractors.name',
            'contractors.contact_person',
            'contractors.phone',
            'contractors.email',
            'contractors.contractor_type',
        ])
            ->join('contracts', 'contractors.id', '=', 'contracts.contractor_id')
            ->where('contractors.organization_id', $organizationId)
            ->where('contracts.project_id', $projectId)
            ->where('contracts.organization_id', $organizationId);

        // Применяем фильтры
        if ($contractorIds) {
            $contractorsQuery->whereIn('contractors.id', $contractorIds);
        }

        if ($contractStatus) {
            $contractorsQuery->where('contracts.status', $contractStatus);
        }

        // Получаем уникальных подрядчиков
        $contractors = $contractorsQuery->distinct()->get();

        $reportData = [];
        $totalSummary = [
            'total_contractors' => $contractors->count(),
            'total_contract_amount' => 0,
            'total_completed_amount' => 0,
            'total_payment_amount' => 0,
            'total_remaining_amount' => 0,
        ];

        foreach ($contractors as $contractor) {
            $contractorData = $this->getContractorSummaryData(
                $contractor,
                $projectId,
                $organizationId,
                $dateFrom,
                $dateTo,
                $contractStatus,
                $includeCompletedWorks,
                $includePayments,
                $includeMaterials
            );

            $reportData[] = $contractorData;

            // Обновляем общую сводку
            $totalSummary['total_contract_amount'] += $contractorData['total_contract_amount'];
            $totalSummary['total_completed_amount'] += $contractorData['total_completed_amount'];
            $totalSummary['total_payment_amount'] += $contractorData['total_payment_amount'];
            $totalSummary['total_remaining_amount'] += $contractorData['remaining_amount'];
        }

        // Сортировка
        $reportData = $this->sortContractorData($reportData, $sortBy, $sortDirection);

        $result = [
            'title' => 'Отчет по подрядчикам',
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
            ],
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'filters' => [
                'contract_status' => $contractStatus,
                'contractor_ids' => $contractorIds,
                'include_completed_works' => $includeCompletedWorks,
                'include_payments' => $includePayments,
                'include_materials' => $includeMaterials,
            ],
            'summary' => $totalSummary,
            'contractors' => $reportData,
            'generated_at' => now()->toISOString(),
        ];

        $templateId = $request->validated('template_id');
        
        // Применяем шаблон к данным если указан (для всех форматов включая JSON)
        if ($templateId && $this->isTemplateModuleActive()) {
            try {
                $reportTemplate = $this->templateService->getTemplateForReport('contractor_summary', $request, $templateId);
                
                if ($reportTemplate && !empty($reportTemplate->columns_config)) {
                    // Получаем список data_key из шаблона
                    $allowedKeys = collect($reportTemplate->columns_config)
                        ->pluck('data_key')
                        ->toArray();
                    
                    // Фильтруем каждого подрядчика, оставляя только выбранные колонки
                    $result['contractors'] = array_map(function ($contractor) use ($allowedKeys) {
                        return array_intersect_key($contractor, array_flip($allowedKeys));
                    }, $result['contractors']);
                    
                    Log::info('[ContractorReport] Template applied to data', [
                        'template_id' => $templateId,
                        'columns_count' => count($allowedKeys)
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('[ContractorReport] Failed to apply template to data', [
                    'template_id' => $templateId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Экспорт в файл, если требуется (кроме json)
        if ($exportFormat && $exportFormat !== 'json') {
            Log::info('[ContractorReport] File export requested', [
                'template_id' => $templateId,
                'format' => $exportFormat
            ]);
            
            // Для файлового экспорта используем специальную логику
            if ($templateId && $this->isTemplateModuleActive()) {
                try {
                    return $this->exportWithTemplate($result, $templateId, $exportFormat, $request);
                } catch (\Exception $e) {
                    Log::warning('[ContractorReport] Failed to use template export, using default', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Дефолтный файловый экспорт
            if ($exportFormat === 'csv') {
                $filename = 'contractor_summary_report_' . now()->format('d-m-Y_H-i');
                return $this->exportToCsv($result, $filename);
            }

            if ($exportFormat === 'excel' || $exportFormat === 'xlsx') {
                $filename = 'contractor_summary_report_' . now()->format('d-m-Y_H-i');
                return $this->exportToExcel($result, $filename);
            }
        }

        return $result;
    }

    /**
     * Получить детальный отчет по конкретному подрядчику.
     */
    public function getContractorDetailReport(ContractorReportRequest $request, int $contractorId): array|StreamedResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->validated('project_id');
        $dateFrom = $request->validated('date_from');
        $dateTo = $request->validated('date_to');
        $exportFormat = $request->validated('export_format');

        // Получаем подрядчика
        $contractor = Contractor::where('id', $contractorId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Получаем проект
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Получаем контракты подрядчика по проекту
        $contractsQuery = Contract::where('contractor_id', $contractorId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->with(['completedWorks', 'agreements']);

        $contracts = $contractsQuery->get();

        $contractsData = [];
        $totalSummary = [
            'total_contracts' => $contracts->count(),
            'total_contract_amount' => 0,
            'total_completed_amount' => 0,
            'total_payment_amount' => 0,
        ];

        foreach ($contracts as $contract) {
            $contractData = $this->getContractDetailData($contract, $dateFrom, $dateTo);
            $contractsData[] = $contractData;

            $totalSummary['total_contract_amount'] += $contractData['total_amount'];
            $totalSummary['total_completed_amount'] += $contractData['completed_amount'];
            $totalSummary['total_payment_amount'] += $contractData['payment_amount'];
        }

        // Преобразуем enum contractor_type в строку для безопасного использования
        $contractorType = $contractor->contractor_type;
        if ($contractorType instanceof \BackedEnum) {
            $contractorType = $contractorType->value;
        } elseif ($contractorType instanceof \UnitEnum) {
            $contractorType = $contractorType->name;
        }

        $result = [
            'title' => 'Детальный отчет по подрядчику',
            'contractor' => [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'contact_person' => $contractor->contact_person,
                'phone' => $contractor->phone,
                'email' => $contractor->email,
                'contractor_type' => $contractorType,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
            ],
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'summary' => $totalSummary,
            'contracts' => $contractsData,
            'generated_at' => now()->toISOString(),
        ];

        $templateId = $request->validated('template_id');
        
        // Применяем шаблон к данным если указан (для всех форматов включая JSON)
        if ($templateId && $this->isTemplateModuleActive()) {
            try {
                $reportTemplate = $this->templateService->getTemplateForReport('contractor_detail', $request, $templateId);
                
                if ($reportTemplate && !empty($reportTemplate->columns_config)) {
                    // Получаем список data_key из шаблона
                    $allowedKeys = collect($reportTemplate->columns_config)
                        ->pluck('data_key')
                        ->toArray();
                    
                    // Фильтруем каждый контракт, оставляя только выбранные колонки
                    $result['contracts'] = array_map(function ($contract) use ($allowedKeys) {
                        // Основные поля контракта
                        $filtered = array_intersect_key($contract, array_flip($allowedKeys));
                        
                        // Сохраняем вложенные массивы (completed_works, payments) если они есть
                        if (isset($contract['completed_works'])) {
                            $filtered['completed_works'] = $contract['completed_works'];
                        }
                        if (isset($contract['payments'])) {
                            $filtered['payments'] = $contract['payments'];
                        }
                        
                        return $filtered;
                    }, $result['contracts']);
                    
                    Log::info('[ContractorReport] Template applied to detail data', [
                        'template_id' => $templateId,
                        'columns_count' => count($allowedKeys)
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('[ContractorReport] Failed to apply template to detail data', [
                    'template_id' => $templateId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Экспорт в файл, если требуется (кроме json)
        if ($exportFormat && $exportFormat !== 'json') {
            if ($exportFormat === 'csv') {
                $filename = 'contractor_detail_report_' . now()->format('d-m-Y_H-i');
                return $this->exportToCsv($result, $filename);
            }

            if ($exportFormat === 'excel' || $exportFormat === 'xlsx') {
                $filename = 'contractor_detail_report_' . now()->format('d-m-Y_H-i');
                return $this->exportToExcel($result, $filename);
            }
        }

        return $result;
    }

    /**
     * Получить сводные данные по подрядчику.
     */
    private function getContractorSummaryData(
        Contractor $contractor,
        int $projectId,
        int $organizationId,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $contractStatus,
        bool $includeCompletedWorks,
        bool $includePayments,
        bool $includeMaterials
    ): array {
        // Получаем контракты подрядчика по проекту
        $contractsQuery = Contract::where('contractor_id', $contractor->id)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->with('agreements');

        if ($contractStatus) {
            $contractsQuery->where('status', $contractStatus);
        }

        $contracts = $contractsQuery->get();

        $totalContractAmount = $this->calculateTotalContractAmount($contracts);
        $totalCompletedAmount = 0;
        $totalPaymentAmount = 0;
        $contractsCount = $contracts->count();

        // Получаем выполненные работы (акты)
        if ($includeCompletedWorks) {
            // Получаем ID контрактов подрядчика
            $contractIds = $contracts->pluck('id');
            
            // Считаем сумму по актам выполненных работ (ContractPerformanceAct)
            $actsQuery = ContractPerformanceAct::whereIn('contract_id', $contractIds)
                ->where('is_approved', true);

            // Если указана дата начала, фильтруем по ней
            if ($dateFrom) {
                $actsQuery->where('act_date', '>=', Carbon::parse($dateFrom)->toDateString());
            }

            // Если указана дата окончания, фильтруем по ней
            if ($dateTo) {
                $actsQuery->where('act_date', '<=', Carbon::parse($dateTo)->toDateString());
            }

            $totalCompletedAmount = $actsQuery->sum('amount');
        }

        // Получаем платежи из новой таблицы invoices
        if ($includePayments) {
            $contractIds = $contracts->pluck('id');
            
            $paymentsQuery = Invoice::where('invoiceable_type', Contract::class)
                ->whereIn('invoiceable_id', $contractIds)
                ->whereNotNull('paid_at');

            // Если указана дата начала, фильтруем по ней. Иначе получаем за все время
            if ($dateFrom) {
                $paymentsQuery->whereDate('paid_at', '>=', $dateFrom);
            }

            // Если указана дата окончания, фильтруем по ней. Иначе получаем за все время
            if ($dateTo) {
                $paymentsQuery->whereDate('paid_at', '<=', $dateTo);
            }

            $totalPaymentAmount = $paymentsQuery->sum('paid_amount');
        }

        // Преобразуем enum contractor_type в строку для безопасного использования
        $contractorType = $contractor->contractor_type;
        if ($contractorType instanceof \BackedEnum) {
            $contractorType = $contractorType->value;
        } elseif ($contractorType instanceof \UnitEnum) {
            $contractorType = $contractorType->name;
        }

        return [
            'contractor_id' => $contractor->id,
            'contractor_name' => $contractor->name,
            'contact_person' => $contractor->contact_person,
            'phone' => $contractor->phone,
            'email' => $contractor->email,
            'contractor_type' => $contractorType,
            'contracts_count' => $contractsCount,
            'total_contract_amount' => round($totalContractAmount, 2),
            'total_completed_amount' => round($totalCompletedAmount, 2),
            'total_payment_amount' => round($totalPaymentAmount, 2),
            'remaining_amount' => round($totalContractAmount - $totalPaymentAmount, 2),
            'completion_percentage' => $totalContractAmount > 0 ? round(($totalCompletedAmount / $totalContractAmount) * 100, 2) : 0,
            'payment_percentage' => $totalContractAmount > 0 ? round(($totalPaymentAmount / $totalContractAmount) * 100, 2) : 0,
        ];
    }

    /**
     * Получить детальные данные по контракту.
     */
    private function getContractDetailData(Contract $contract, ?string $dateFrom, ?string $dateTo): array
    {
        $contract->load('agreements');
        
        // Получаем акты выполненных работ
        $actsQuery = $contract->performanceActs()->where('is_approved', true);

        // Если указана дата начала, фильтруем по ней
        if ($dateFrom) {
            $actsQuery->where('act_date', '>=', Carbon::parse($dateFrom)->toDateString());
        }

        // Если указана дата окончания, фильтруем по ней
        if ($dateTo) {
            $actsQuery->where('act_date', '<=', Carbon::parse($dateTo)->toDateString());
        }

        $acts = $actsQuery->get();
        $completedAmount = $acts->sum('amount');
        
        // Используем новую таблицу invoices для получения платежей
        $paymentsQuery = Invoice::where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->id)
            ->whereNotNull('paid_at');

        if ($dateFrom) {
            $paymentsQuery->whereDate('paid_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $paymentsQuery->whereDate('paid_at', '<=', $dateTo);
        }

        $payments = $paymentsQuery->get();
        $paymentAmount = $payments->sum('paid_amount');
        
        $effectiveTotalAmount = $this->calculateSingleContractAmount($contract);

        return [
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'contract_date' => $contract->date?->format('Y-m-d'),
            'status' => $contract->status,
            'total_amount' => round($effectiveTotalAmount, 2),
            'completed_amount' => round($completedAmount, 2),
            'payment_amount' => round($paymentAmount, 2),
            'remaining_amount' => round($effectiveTotalAmount - $paymentAmount, 2),
            'completion_percentage' => $effectiveTotalAmount > 0 ? round(($completedAmount / $effectiveTotalAmount) * 100, 2) : 0,
            'payment_percentage' => $effectiveTotalAmount > 0 ? round(($paymentAmount / $effectiveTotalAmount) * 100, 2) : 0,
            'performance_acts' => $acts->map(function ($act) {
                return [
                    'id' => $act->id,
                    'act_document_number' => $act->act_document_number,
                    'act_date' => $act->act_date?->format('Y-m-d'),
                    'amount' => $act->amount,
                    'description' => $act->description,
                    'is_approved' => $act->is_approved,
                    'approval_date' => $act->approval_date?->format('Y-m-d'),
                ];
            })->toArray(),
            'payments' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->paid_amount,
                    'payment_date' => $payment->paid_at?->format('Y-m-d'),
                    'payment_type' => $payment->invoice_type instanceof \BackedEnum ? $payment->invoice_type->value : $payment->invoice_type,
                    'notes' => $payment->notes,
                ];
            })->toArray(),
        ];
    }

    /**
     * Сортировка данных подрядчиков.
     */
    private function sortContractorData(array $data, string $sortBy, string $sortDirection): array
    {
        usort($data, function ($a, $b) use ($sortBy, $sortDirection) {
            $valueA = $a[$sortBy] ?? 0;
            $valueB = $b[$sortBy] ?? 0;

            if ($sortDirection === 'desc') {
                return $valueB <=> $valueA;
            }

            return $valueA <=> $valueB;
        });

        return $data;
    }

    /**
     * Проверить, активен ли модуль шаблонов отчетов.
     */
    protected function isTemplateModuleActive(): bool
    {
        try {
            $accessController = app(\App\Modules\Core\AccessController::class);
            $user = Auth::user();
            
            if (!$user || !$user->current_organization_id) {
                return false;
            }
            
            return $accessController->hasModuleAccess(
                $user->current_organization_id,
                'report-templates'
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Получить дефолтный маппинг колонок для сводного отчета.
     */
    protected function getDefaultColumnMapping(): array
    {
        return [
            'Подрядчик' => 'contractor_name',
            'Контактное лицо' => 'contact_person',
            'Телефон' => 'phone',
            'Email' => 'email',
            'Количество контрактов' => 'contracts_count',
            'Сумма контрактов' => 'total_contract_amount',
            'Выполнено работ' => 'total_completed_amount',
            'Оплачено' => 'total_payment_amount',
            'Остаток к доплате' => 'remaining_amount',
            'Процент выполнения' => 'completion_percentage',
            'Процент оплаты' => 'payment_percentage',
        ];
    }

    /**
     * Получить маппинг колонок из шаблона или вернуть дефолтный.
     */
    protected function getColumnMappingFromTemplate($template, array $defaultMapping): array
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
        return $defaultMapping;
    }

    /**
     * Экспорт с использованием шаблона.
     */
    protected function exportWithTemplate(array $data, int $templateId, string $format, $request): StreamedResponse
    {
        $reportTemplate = $this->templateService->getTemplateForReport('contractor_summary', $request, $templateId);
        
        if (!$reportTemplate) {
            throw new \Exception('Шаблон отчета не найден');
        }

        $defaultMapping = $this->getDefaultColumnMapping();
        $columnMapping = $this->getColumnMappingFromTemplate($reportTemplate, $defaultMapping);

        if (empty($columnMapping)) {
            throw new \Exception('Не удалось определить колонки для отчета');
        }

        // Фильтруем данные по выбранным колонкам
        $filteredData = $this->filterDataByColumns($data['contractors'], $columnMapping);

        // Формируем headers и rows
        $headers = array_keys($columnMapping);
        $rows = [];
        
        foreach ($filteredData as $row) {
            $rowData = [];
            foreach ($columnMapping as $header => $dataKey) {
                $value = $row[$dataKey] ?? '';
                
                // Форматируем процентные значения
                if (str_contains($dataKey, 'percentage')) {
                    $value = $format === 'csv' ? $value . '%' : (float)$value;
                } elseif ($format === 'xlsx' && is_numeric($value)) {
                    $value = (float)$value;
                } elseif ($format === 'xlsx') {
                    $value = (string)$value;
                }
                
                $rowData[] = $value;
            }
            $rows[] = $rowData;
        }

        $filename = $reportTemplate->name ? str_replace(' ', '_', $reportTemplate->name) : 'contractor_summary_report';
        $filename .= '_' . now()->format('d-m-Y_H-i');

        if ($format === 'csv') {
            return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
        } else {
            return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
        }
    }

    /**
     * Фильтрация данных по выбранным колонкам.
     */
    protected function filterDataByColumns(array $data, array $columnMapping): array
    {
        $dataKeys = array_values($columnMapping);
        
        return array_map(function ($item) use ($dataKeys) {
            $filtered = [];
            foreach ($dataKeys as $key) {
                $filtered[$key] = $item[$key] ?? null;
            }
            return $filtered;
        }, $data);
    }

    /**
     * Экспорт в CSV.
     */
    private function exportToCsv(array $data, string $filename): StreamedResponse
    {
        // Проверяем тип отчета по наличию ключей
        if (isset($data['contractors'])) {
            // Сводный отчет
            $response = $this->exportContractorsSummaryToCsv($data, $filename);
        } elseif (isset($data['contracts'])) {
            // Детальный отчет
            $response = $this->exportContractorDetailToCsv($data, $filename);
        } else {
            throw new \InvalidArgumentException('Неподдерживаемый формат данных для экспорта');
        }
        
        // Сохраняем файл в S3 в фоне
        register_shutdown_function(function () use ($filename) {
            $this->saveReportFileToS3($filename . '.csv', 'csv', 'contractor_report');
        });
        
        return $response;
    }
    
    /**
     * Экспорт сводного отчета в CSV.
     */
    private function exportContractorsSummaryToCsv(array $data, string $filename): StreamedResponse
    {
        $headers = [
            'Подрядчик',
            'Контактное лицо',
            'Телефон',
            'Email',
            'Количество контрактов',
            'Сумма контрактов',
            'Выполнено работ',
            'Оплачено',
            'Остаток к доплате',
            'Процент выполнения',
            'Процент оплаты',
        ];

        $rows = [];
        foreach ($data['contractors'] as $contractor) {
            $rows[] = [
                $contractor['contractor_name'],
                $contractor['contact_person'],
                $contractor['phone'],
                $contractor['email'],
                $contractor['contracts_count'],
                $contractor['total_contract_amount'],
                $contractor['total_completed_amount'],
                $contractor['total_payment_amount'],
                $contractor['remaining_amount'],
                $contractor['completion_percentage'].'%',
                $contractor['payment_percentage'].'%',
            ];
        }

        return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
    }
    
    /**
     * Экспорт детального отчета в CSV.
     */
    private function exportContractorDetailToCsv(array $data, string $filename): StreamedResponse
    {
        $headers = [
            'Номер контракта',
            'Дата контракта',
            'Статус',
            'Сумма контракта',
            'Выполнено работ',
            'Оплачено',
            'Остаток к доплате',
            'Процент выполнения',
            'Процент оплаты',
        ];

        $rows = [];
        foreach ($data['contracts'] as $contract) {
            $rows[] = [
                $contract['contract_number'],
                $contract['contract_date'],
                $contract['status'],
                $contract['total_amount'],
                $contract['completed_amount'],
                $contract['payment_amount'],
                $contract['remaining_amount'],
                $contract['completion_percentage'].'%',
                $contract['payment_percentage'].'%',
            ];
        }

        return $this->csvExporter->streamDownload($filename . '.csv', $headers, $rows);
    }

    /**
     * Экспорт в Excel.
     */
    private function exportToExcel(array $data, string $filename): StreamedResponse
    {
        // Проверяем тип отчета по наличию ключей
        if (isset($data['contractors'])) {
            // Сводный отчет
            $response = $this->exportContractorsSummaryToExcel($data, $filename);
        } elseif (isset($data['contracts'])) {
            // Детальный отчет
            $response = $this->exportContractorDetailToExcel($data, $filename);
        } else {
            throw new \InvalidArgumentException('Неподдерживаемый формат данных для экспорта');
        }
        
        // Сохраняем файл в S3 в фоне
        register_shutdown_function(function () use ($filename) {
            $this->saveReportFileToS3($filename . '.xlsx', 'xlsx', 'contractor_report');
        });
        
        return $response;
    }
    
    /**
     * Экспорт сводного отчета в Excel.
     */
    private function exportContractorsSummaryToExcel(array $data, string $filename): StreamedResponse
    {
        $headers = [
            'Подрядчик',
            'Контактное лицо',
            'Телефон',
            'Email',
            'Количество контрактов',
            'Сумма контрактов',
            'Выполнено работ',
            'Оплачено',
            'Остаток к доплате',
            'Процент выполнения',
            'Процент оплаты',
        ];

        $rows = [];
        foreach ($data['contractors'] as $contractor) {
            $rows[] = [
                (string)($contractor['contractor_name'] ?? ''),
                (string)($contractor['contact_person'] ?? ''),
                (string)($contractor['phone'] ?? ''),
                (string)($contractor['email'] ?? ''),
                (float)($contractor['contracts_count'] ?? 0),
                (float)($contractor['total_contract_amount'] ?? 0),
                (float)($contractor['total_completed_amount'] ?? 0),
                (float)($contractor['total_payment_amount'] ?? 0),
                (float)($contractor['remaining_amount'] ?? 0),
                (float)($contractor['completion_percentage'] ?? 0),
                (float)($contractor['payment_percentage'] ?? 0),
            ];
        }

        return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
    }
    
    /**
     * Экспорт детального отчета в Excel.
     */
    private function exportContractorDetailToExcel(array $data, string $filename): StreamedResponse
    {
        $headers = [
            'Номер контракта',
            'Дата контракта',
            'Статус',
            'Сумма контракта',
            'Выполнено работ',
            'Оплачено',
            'Остаток к доплате',
            'Процент выполнения',
            'Процент оплаты',
        ];

        $rows = [];
        foreach ($data['contracts'] as $contract) {
            $rows[] = [
                (string)($contract['contract_number'] ?? ''),
                (string)($contract['contract_date'] ?? ''),
                (string)($contract['status'] ?? ''),
                (float)($contract['total_amount'] ?? 0),
                (float)($contract['completed_amount'] ?? 0),
                (float)($contract['payment_amount'] ?? 0),
                (float)($contract['remaining_amount'] ?? 0),
                (float)($contract['completion_percentage'] ?? 0),
                (float)($contract['payment_percentage'] ?? 0),
            ];
        }

        return $this->excelExporter->streamDownload($filename . '.xlsx', $headers, $rows);
    }

    /**
     * Сохранение файла отчета в S3 и создание записи в базе данных.
     * Выполняется синхронно в фоне после отдачи файла пользователю.
     */
    private function saveReportFileToS3(string $filename, string $extension, string $type): void
    {
        try {
            $user = Auth::user();
            $organizationId = $user->current_organization_id;
            
            if (!$user || !$organizationId) {
                Log::error('Не удалось определить пользователя или организацию для сохранения отчета');
                return;
            }
            
            $org = Organization::find($organizationId);
            $fileService = app(FileService::class);
            $disk = $fileService->disk($org);
            
            $path = "reports/contractor/{$filename}";
            
            // Создаем временный файл
            $tempFile = tempnam(sys_get_temp_dir(), 'contractor_report_');
            
            if ($extension === 'xlsx') {
                $this->createSimpleExcelFile($tempFile, $filename);
            } else {
                $this->createSimpleCsvFile($tempFile, $filename);
            }
            
            // Сохраняем в S3
            $content = file_get_contents($tempFile);
            $disk->put($path, $content, 'public');
            
            // Создаем запись в БД
            ReportFile::create([
                'organization_id' => $organizationId,
                'path' => $path,
                'type' => $type,
                'filename' => $filename,
                'name' => 'Отчет по подрядчикам',
                'size' => strlen($content),
                'expires_at' => now()->addYear(),
                'user_id' => $user->id,
            ]);
            
            // Удаляем временный файл
            unlink($tempFile);
            
            Log::info('Отчет по подрядчикам сохранен в S3', [
                'path' => $path,
                'organization_id' => $organizationId,
                'type' => $type
            ]);
            
        } catch (Exception $e) {
            Log::error('Ошибка сохранения отчета по подрядчикам в S3', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Создание простого Excel файла для демонстрации.
     */
    private function createSimpleExcelFile(string $filePath, string $filename): void
    {
        $excelExporter = app(ExcelExporterService::class);
        
        $headers = [
            'Подрядчик',
            'Количество договоров',
            'Общая сумма договоров',
            'Выполненные работы',
            'Оплачено',
            'К доплате',
        ];
        
        $exportData = [
            ['Пример подрядчика 1', '5', '1 000 000', '800 000', '600 000', '200 000'],
            ['Пример подрядчика 2', '3', '500 000', '400 000', '300 000', '100 000'],
        ];
        
        $excelExporter->saveToFile($exportData, $headers, $filePath);
    }

    /**
     * Создание простого CSV файла для демонстрации.
     */
    private function createSimpleCsvFile(string $filePath, string $filename): void
    {
        $csvExporter = app(CsvExporterService::class);
        
        $headers = [
            'Подрядчик',
            'Количество договоров',
            'Общая сумма договоров',
            'Выполненные работы',
            'Оплачено',
            'К доплате',
        ];
        
        $exportData = [
            ['Пример подрядчика 1', '5', '1 000 000', '800 000', '600 000', '200 000'],
            ['Пример подрядчика 2', '3', '500 000', '400 000', '300 000', '100 000'],
        ];
        
        $csvExporter->saveToFile($exportData, $headers, $filePath);
    }

    /**
     * Рассчитать общую сумму контрактов с учетом дополнительных соглашений.
     */
    private function calculateTotalContractAmount($contracts): float
    {
        $total = 0;
        foreach ($contracts as $contract) {
            $total += $this->calculateSingleContractAmount($contract);
        }
        return $total;
    }

    /**
     * Рассчитать сумму одного контракта с учетом дополнительных соглашений.
     */
    private function calculateSingleContractAmount(Contract $contract): float
    {
        $baseAmount = (float) ($contract->total_amount ?? 0);
        $agreementsDelta = 0;
        
        if ($contract->relationLoaded('agreements')) {
            $agreementsDelta = $contract->agreements->sum('change_amount') ?? 0;
        }
        
        return $baseAmount + $agreementsDelta;
    }

}
