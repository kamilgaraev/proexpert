<?php

namespace App\Services\Report;

use App\Http\Requests\Api\V1\Admin\ContractorReportRequest;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPayment;
use App\Models\Project;
use App\Models\ReportFile;
use App\Models\Organization;
use App\Services\Export\CsvExporterService;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use App\Services\Organization\OrganizationContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

class ContractorReportService
{
    protected CsvExporterService $csvExporter;

    protected ExcelExporterService $excelExporter;

    public function __construct(
        CsvExporterService $csvExporter,
        ExcelExporterService $excelExporter
    ) {
        $this->csvExporter = $csvExporter;
        $this->excelExporter = $excelExporter;
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

        // Экспорт в файл, если требуется
        if ($exportFormat === 'csv') {
            return $this->exportToCsv($result, 'contractor_summary_report');
        }

        if ($exportFormat === 'excel' || $exportFormat === 'xlsx') {
            return $this->exportToExcel($result, 'contractor_summary_report');
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
            ->with(['completedWorks', 'payments', 'agreements']);

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

        $result = [
            'title' => 'Детальный отчет по подрядчику',
            'contractor' => [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'contact_person' => $contractor->contact_person,
                'phone' => $contractor->phone,
                'email' => $contractor->email,
                'contractor_type' => $contractor->contractor_type,
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

        // Экспорт в файл, если требуется
        if ($exportFormat === 'csv') {
            return $this->exportToCsv($result, 'contractor_detail_report');
        }

        if ($exportFormat === 'excel' || $exportFormat === 'xlsx') {
            return $this->exportToExcel($result, 'contractor_detail_report');
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

        // Получаем выполненные работы
        if ($includeCompletedWorks) {
            // Получаем ID контрактов подрядчика для правильного расчета выполненных работ
            $contractIds = $contracts->pluck('id');
            
            $completedWorksQuery = CompletedWork::whereIn('contract_id', $contractIds);

            if ($dateFrom) {
                $completedWorksQuery->where('completion_date', '>=', Carbon::parse($dateFrom)->toDateString());
            }

            if ($dateTo) {
                $completedWorksQuery->where('completion_date', '<=', Carbon::parse($dateTo)->toDateString());
            }

            $totalCompletedAmount = $completedWorksQuery->sum('total_amount');
        }

        // Получаем платежи
        if ($includePayments) {
            $contractIds = $contracts->pluck('id');
            $paymentsQuery = ContractPayment::whereIn('contract_id', $contractIds);

            if ($dateFrom) {
                $paymentsQuery->where('payment_date', '>=', Carbon::parse($dateFrom)->toDateString());
            }

            if ($dateTo) {
                $paymentsQuery->where('payment_date', '<=', Carbon::parse($dateTo)->toDateString());
            }

            $totalPaymentAmount = $paymentsQuery->sum('amount');
        }

        return [
            'contractor_id' => $contractor->id,
            'contractor_name' => $contractor->name,
            'contact_person' => $contractor->contact_person,
            'phone' => $contractor->phone,
            'email' => $contractor->email,
            'contractor_type' => $contractor->contractor_type,
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
        
        $completedWorksQuery = $contract->completedWorks();
        $paymentsQuery = $contract->payments();

        if ($dateFrom) {
            $completedWorksQuery->where('completion_date', '>=', Carbon::parse($dateFrom)->toDateString());
            $paymentsQuery->where('payment_date', '>=', Carbon::parse($dateFrom)->toDateString());
        }

        if ($dateTo) {
            $completedWorksQuery->where('completion_date', '<=', Carbon::parse($dateTo)->toDateString());
            $paymentsQuery->where('payment_date', '<=', Carbon::parse($dateTo)->toDateString());
        }

        $completedWorks = $completedWorksQuery->with('workType')->get();
        $payments = $paymentsQuery->get();

        $completedAmount = $completedWorks->sum('total_amount');
        $paymentAmount = $payments->sum('amount');
        
        $effectiveTotalAmount = $this->calculateSingleContractAmount($contract);

        return [
            'contract_id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'contract_date' => $contract->contract_date?->format('Y-m-d'),
            'status' => $contract->status,
            'total_amount' => round($effectiveTotalAmount, 2),
            'completed_amount' => round($completedAmount, 2),
            'payment_amount' => round($paymentAmount, 2),
            'remaining_amount' => round($effectiveTotalAmount - $paymentAmount, 2),
            'completion_percentage' => $effectiveTotalAmount > 0 ? round(($completedAmount / $effectiveTotalAmount) * 100, 2) : 0,
            'payment_percentage' => $effectiveTotalAmount > 0 ? round(($paymentAmount / $effectiveTotalAmount) * 100, 2) : 0,
            'completed_works' => $completedWorks->map(function ($work) {
                return [
                    'id' => $work->id,
                    'work_type' => $work->workType?->name,
                    'quantity' => $work->quantity,
                    'price' => $work->price,
                    'total_amount' => $work->total_amount,
                    'completion_date' => $work->completion_date?->format('Y-m-d'),
                    'notes' => $work->notes,
                ];
            })->toArray(),
            'payments' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date?->format('Y-m-d'),
                    'payment_type' => $payment->payment_type,
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
            'Тип',
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
                $contractor['contractor_type'],
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
            'Тип',
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
                $contractor['contractor_type'],
                $contractor['contracts_count'],
                $contractor['total_contract_amount'],
                $contractor['total_completed_amount'],
                $contractor['total_payment_amount'],
                $contractor['remaining_amount'],
                $contractor['completion_percentage'],
                $contractor['payment_percentage'],
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
                $contract['contract_number'],
                $contract['contract_date'],
                $contract['status'],
                $contract['total_amount'],
                $contract['completed_amount'],
                $contract['payment_amount'],
                $contract['remaining_amount'],
                $contract['completion_percentage'],
                $contract['payment_percentage'],
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
