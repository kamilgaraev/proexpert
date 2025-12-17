<?php

namespace App\Services\Report;

use App\Http\Requests\Api\V1\Admin\ContractorReportRequest;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\Contract;
use App\Models\Contractor;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
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
        
        // НОВЫЕ ФИЛЬТРЫ
        $filterMultiProject = $request->validated('filter_multi_project');
        $showAllocationDetails = $request->validated('show_allocation_details');
        $allocationTypeFilter = $request->validated('allocation_type_filter');

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
            ->where('contracts.organization_id', $organizationId)
            ->where(function($q) use ($projectId) {
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

        // Применяем фильтры
        if ($contractorIds) {
            $contractorsQuery->whereIn('contractors.id', $contractorIds);
        }

        if ($contractStatus) {
            $contractorsQuery->where('contracts.status', $contractStatus);
        }

        // НОВЫЙ ФИЛЬТР: Фильтрация по мультипроектным контрактам
        if ($filterMultiProject === 'only_multi') {
            $contractorsQuery->where('contracts.is_multi_project', true);
        } elseif ($filterMultiProject === 'exclude_multi') {
            $contractorsQuery->where('contracts.is_multi_project', false);
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
                $includeMaterials,
                $showAllocationDetails,
                $allocationTypeFilter
            );

            // Пропускаем подрядчиков без контрактов в этом проекте (после применения фильтров)
            if ($contractorData['contracts_count'] === 0) {
                continue;
            }

            $reportData[] = $contractorData;

            // Обновляем общую сводку
            $totalSummary['total_contract_amount'] += $contractorData['total_contract_amount'];
            $totalSummary['total_completed_amount'] += $contractorData['total_completed_amount'];
            $totalSummary['total_payment_amount'] += $contractorData['total_payment_amount'];
            $totalSummary['total_remaining_amount'] += $contractorData['remaining_amount'];
        }

        // Обновляем количество подрядчиков после фильтрации
        $totalSummary['total_contractors'] = count($reportData);

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
            ->where('organization_id', $organizationId)
            ->where(function($q) use ($projectId) {
                // Обычные контракты (project_id)
                $q->where('project_id', $projectId)
                  // ИЛИ мультипроектные контракты (через pivot таблицу)
                  ->orWhereExists(function($sub) use ($projectId) {
                      $sub->select(DB::raw(1))
                          ->from('contract_project')
                          ->whereColumn('contract_project.contract_id', 'contracts.id')
                          ->where('contract_project.project_id', $projectId);
                  });
            })
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
            $contractData = $this->getContractDetailData($contract, $dateFrom, $dateTo, $projectId);
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

        // Рассчитываем расширенную статистику
        $extendedSummary = $this->calculateExtendedSummary($contracts, $contractsData, $projectId);

        $result = [
            'title' => 'Детальный отчет по подрядчику',
            'contractor' => [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'contact_person' => $contractor->contact_person,
                'phone' => $contractor->phone,
                'email' => $contractor->email,
                'legal_address' => $contractor->legal_address,
                'inn' => $contractor->inn,
                'kpp' => $contractor->kpp,
                'bank_details' => $contractor->bank_details,
                'notes' => $contractor->notes,
                'contractor_type' => $contractorType,
                'connected_at' => $contractor->connected_at?->toISOString(),
                'last_sync_at' => $contractor->last_sync_at?->toISOString(),
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
            'summary' => array_merge($totalSummary, $extendedSummary),
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
        bool $includeMaterials,
        bool $showAllocationDetails = false,
        string $allocationTypeFilter = 'all'
    ): array {
        // Получаем контракты подрядчика по проекту
        $contractsQuery = Contract::where('contractor_id', $contractor->id)
            ->where('organization_id', $organizationId)
            ->where(function($q) use ($projectId) {
                // Обычные контракты (project_id)
                $q->where('project_id', $projectId)
                  // ИЛИ мультипроектные контракты (через pivot таблицу)
                  ->orWhereExists(function($sub) use ($projectId) {
                      $sub->select(DB::raw(1))
                          ->from('contract_project')
                          ->whereColumn('contract_project.contract_id', 'contracts.id')
                          ->where('contract_project.project_id', $projectId);
                  });
            })
            ->with('agreements');

        if ($contractStatus) {
            $contractsQuery->where('status', $contractStatus);
        }

        $contracts = $contractsQuery->get();

        $totalContractAmount = $this->calculateTotalContractAmount($contracts, $projectId);
        $totalCompletedAmount = 0;
        $totalPaymentAmount = 0;
        $contractsCount = $contracts->count();

        // Получаем выполненные работы (акты)
        if ($includeCompletedWorks) {
            // Получаем ID контрактов подрядчика
            $contractIds = $contracts->pluck('id');
            
            // Считаем сумму по актам выполненных работ (ContractPerformanceAct)
            // Фильтруем по project_id, чтобы для мультипроектных контрактов брались только акты этого проекта
            $actsQuery = ContractPerformanceAct::whereIn('contract_id', $contractIds)
                ->where('project_id', $projectId)
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

        if ($includePayments) {
            $contractIds = $contracts->pluck('id')->toArray();
            
            if (!empty($contractIds)) {
                $paymentsQuery = PaymentDocument::query()
                    ->where('invoiceable_type', Contract::class)
                    ->whereIn('invoiceable_id', $contractIds)
                    ->where('organization_id', $organizationId)
                    ->where('paid_amount', '>', 0)
                    ->whereIn('status', [
                        PaymentDocumentStatus::PAID,
                        PaymentDocumentStatus::PARTIALLY_PAID,
                    ]);

                if ($dateFrom) {
                    $paymentsQuery->where(function ($q) use ($dateFrom) {
                        $q->whereDate('paid_at', '>=', Carbon::parse($dateFrom)->toDateString())
                            ->orWhere(function ($subQ) use ($dateFrom) {
                                $subQ->whereNull('paid_at')
                                    ->whereDate('updated_at', '>=', Carbon::parse($dateFrom)->toDateString());
                            });
                    });
                }

                if ($dateTo) {
                    $paymentsQuery->where(function ($q) use ($dateTo) {
                        $q->whereDate('paid_at', '<=', Carbon::parse($dateTo)->toDateString())
                            ->orWhere(function ($subQ) use ($dateTo) {
                                $subQ->whereNull('paid_at')
                                    ->whereDate('updated_at', '<=', Carbon::parse($dateTo)->toDateString());
                            });
                    });
                }

                // Для мультипроектных контрактов распределяем платежи пропорционально
                $totalPaymentAmount = $this->calculatePaymentsForProject(
                    $paymentsQuery->get(), 
                    $contracts, 
                    $projectId
                );
            }
        }

        // Преобразуем enum contractor_type в строку для безопасного использования
        $contractorType = $contractor->contractor_type;
        if ($contractorType instanceof \BackedEnum) {
            $contractorType = $contractorType->value;
        } elseif ($contractorType instanceof \UnitEnum) {
            $contractorType = $contractorType->name;
        }

        $result = [
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
            'remaining_to_complete' => round($totalContractAmount - $totalCompletedAmount, 2),
            'remaining_amount' => round($totalContractAmount - $totalPaymentAmount, 2),
            'completion_percentage' => $totalContractAmount > 0 ? round(($totalCompletedAmount / $totalContractAmount) * 100, 2) : 0,
            'payment_percentage' => $totalContractAmount > 0 ? round(($totalPaymentAmount / $totalContractAmount) * 100, 2) : 0,
        ];

        // НОВАЯ ФУНКЦИЯ: Добавляем детали распределения если запрошено
        if ($showAllocationDetails) {
            $result['allocation_details'] = $this->getAllocationDetails($contracts, $projectId, $allocationTypeFilter);
        }

        return $result;
    }

    /**
     * Получить детали распределения контрактов
     */
    private function getAllocationDetails($contracts, int $projectId, string $allocationTypeFilter): array
    {
        $details = [];
        
        foreach ($contracts as $contract) {
            if (!$contract->is_multi_project) {
                continue;
            }

            $allocation = $contract->allocationForProject($projectId);
            
            // Фильтр по типу распределения
            if ($allocationTypeFilter !== 'all') {
                if (!$allocation || $allocation->allocation_type->value !== $allocationTypeFilter) {
                    continue;
                }
            }

            $details[] = [
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'is_multi_project' => true,
                'has_explicit_allocation' => $allocation !== null,
                'allocation_type' => $allocation ? $allocation->allocation_type->value : 'auto_fallback',
                'allocation_type_label' => $allocation ? $allocation->allocation_type->label() : 'Авто (fallback)',
                'allocated_amount' => round($contract->getAllocatedAmount($projectId), 2),
                'total_contract_amount' => round((float) $contract->total_amount, 2),
                'allocation_percentage' => $contract->total_amount > 0 
                    ? round(($contract->getAllocatedAmount($projectId) / (float) $contract->total_amount) * 100, 2) 
                    : 0,
            ];
        }

        return $details;
    }

    /**
     * Получить детальные данные по контракту.
     */
    private function getContractDetailData(Contract $contract, ?string $dateFrom, ?string $dateTo, ?int $projectId = null): array
    {
        $contract->load(['agreements', 'estimate', 'completedWorks.workType', 'completedWorks.materials']);
        
        // Получаем акты выполненных работ
        // Для мультипроектных контрактов фильтруем по project_id
        $actsQuery = $contract->performanceActs()->where('is_approved', true);
        
        if ($projectId !== null) {
            $actsQuery->where('project_id', $projectId);
        }

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
        
        $paymentsQuery = PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->id)
            ->where('organization_id', $contract->organization_id)
            ->where('paid_amount', '>', 0)
            ->whereIn('status', [
                PaymentDocumentStatus::PAID,
                PaymentDocumentStatus::PARTIALLY_PAID,
            ])
            ->select('id', 'document_number', 'paid_amount', 'paid_at', 'invoice_type', 'status', 'payment_purpose', 'notes');

        if ($dateFrom) {
            $paymentsQuery->where(function ($q) use ($dateFrom) {
                $q->whereDate('paid_at', '>=', Carbon::parse($dateFrom)->toDateString())
                    ->orWhere(function ($subQ) use ($dateFrom) {
                        $subQ->whereNull('paid_at')
                            ->whereDate('updated_at', '>=', Carbon::parse($dateFrom)->toDateString());
                    });
            });
        }

        if ($dateTo) {
            $paymentsQuery->where(function ($q) use ($dateTo) {
                $q->whereDate('paid_at', '<=', Carbon::parse($dateTo)->toDateString())
                    ->orWhere(function ($subQ) use ($dateTo) {
                        $subQ->whereNull('paid_at')
                            ->whereDate('updated_at', '<=', Carbon::parse($dateTo)->toDateString());
                    });
            });
        }

        $payments = $paymentsQuery->get();
        $paymentAmount = $payments->sum('paid_amount') ?? 0;
        
        $effectiveTotalAmount = $this->calculateSingleContractAmount($contract, $projectId);

        // Получаем выполненные работы
        $completedWorksQuery = CompletedWork::where('contract_id', $contract->id)
            ->where('status', 'confirmed');
        
        if ($projectId !== null) {
            $completedWorksQuery->where('project_id', $projectId);
        }

        if ($dateFrom) {
            $completedWorksQuery->where('completion_date', '>=', Carbon::parse($dateFrom)->toDateString());
        }

        if ($dateTo) {
            $completedWorksQuery->where('completion_date', '<=', Carbon::parse($dateTo)->toDateString());
        }

        $completedWorks = $completedWorksQuery->with(['workType', 'materials'])->get();

        // Статистика по видам работ
        $workTypesStats = $completedWorks->groupBy('work_type_id')->map(function ($works) {
            $firstWork = $works->first();
            $workType = $firstWork->workType;
            return [
                'work_type_id' => $workType?->id,
                'work_type_name' => $workType?->name ?? 'Не указан',
                'work_type_code' => $workType?->code,
                'works_count' => $works->count(),
                'total_quantity' => round($works->sum('quantity'), 3),
                'total_amount' => round($works->sum('total_amount'), 2),
                'average_price' => $works->count() > 0 ? round($works->sum('total_amount') / $works->count(), 2) : 0,
            ];
        })->values()->toArray();

        // Статистика по материалам
        $materialsStats = [];
        foreach ($completedWorks as $work) {
            foreach ($work->materials as $material) {
                $materialId = $material->id;
                if (!isset($materialsStats[$materialId])) {
                    $materialsStats[$materialId] = [
                        'material_id' => $material->id,
                        'material_name' => $material->name,
                        'material_unit' => $material->measurement_unit_id,
                        'total_quantity' => 0,
                        'total_amount' => 0,
                    ];
                }
                $materialsStats[$materialId]['total_quantity'] += $material->pivot->quantity ?? 0;
                $materialsStats[$materialId]['total_amount'] += $material->pivot->total_amount ?? 0;
            }
        }
        $materialsStats = array_values($materialsStats);

        // Преобразуем статус контракта
        $contractStatus = $contract->status;
        if ($contractStatus instanceof \BackedEnum) {
            $contractStatus = $contractStatus->value;
        }

        // Преобразуем work_type_category
        $workTypeCategory = $contract->work_type_category;
        if ($workTypeCategory instanceof \BackedEnum) {
            $workTypeCategory = $workTypeCategory->value;
        }

        // Дополнительные соглашения
        $agreements = $contract->agreements->map(function ($agreement) {
            return [
                'id' => $agreement->id,
                'number' => $agreement->number,
                'agreement_date' => $agreement->agreement_date?->format('Y-m-d'),
                'change_amount' => round((float) $agreement->change_amount, 2),
                'subject_changes' => $agreement->subject_changes,
            ];
        })->toArray();

        // Информация о смете
        $estimateInfo = null;
        if ($contract->estimate) {
            $estimateInfo = [
                'id' => $contract->estimate->id,
                'number' => $contract->estimate->number,
                'name' => $contract->estimate->name,
                'total_amount' => round((float) $contract->estimate->total_amount, 2),
                'estimate_date' => $contract->estimate->estimate_date?->format('Y-m-d'),
            ];
        }

        // Рассчитываем GP и другие показатели
        $baseAmount = (float) ($contract->base_amount ?? $effectiveTotalAmount);
        $gpAmount = (float) ($contract->gp_amount ?? 0);
        $warrantyRetentionAmount = (float) ($contract->warranty_retention_amount ?? 0);

        return [
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'contract_date' => $contract->date?->format('Y-m-d'),
            'subject' => $contract->subject,
            'status' => $contractStatus,
            'work_type_category' => $workTypeCategory,
            'payment_terms' => $contract->payment_terms,
            'start_date' => $contract->start_date?->format('Y-m-d'),
            'end_date' => $contract->end_date?->format('Y-m-d'),
            'is_multi_project' => $contract->is_multi_project ?? false,
            'is_fixed_amount' => $contract->is_fixed_amount ?? true,
            'notes' => $contract->notes,
            
            // Суммы
            'base_amount' => round($baseAmount, 2),
            'total_amount' => round($effectiveTotalAmount, 2),
            'gp_percentage' => $contract->gp_percentage ? round((float) $contract->gp_percentage, 3) : null,
            'gp_amount' => round($gpAmount, 2),
            'warranty_retention_percentage' => $contract->warranty_retention_percentage ? round((float) $contract->warranty_retention_percentage, 3) : null,
            'warranty_retention_amount' => round($warrantyRetentionAmount, 2),
            'subcontract_amount' => $contract->subcontract_amount ? round((float) $contract->subcontract_amount, 2) : null,
            
            // Авансы
            'planned_advance_amount' => $contract->planned_advance_amount ? round((float) $contract->planned_advance_amount, 2) : null,
            'actual_advance_amount' => $contract->actual_advance_amount ? round((float) $contract->actual_advance_amount, 2) : null,
            'remaining_advance_amount' => round((float) ($contract->remaining_advance_amount ?? 0), 2),
            'advance_payment_percentage' => round((float) ($contract->advance_payment_percentage ?? 0), 2),
            
            // Выполнение и оплата
            'completed_amount' => round($completedAmount, 2),
            'payment_amount' => round($paymentAmount, 2),
            'remaining_amount' => round($effectiveTotalAmount - $paymentAmount, 2),
            'remaining_to_complete' => round($effectiveTotalAmount - $completedAmount, 2),
            'completion_percentage' => $effectiveTotalAmount > 0 ? round(($completedAmount / $effectiveTotalAmount) * 100, 2) : 0,
            'payment_percentage' => $effectiveTotalAmount > 0 ? round(($paymentAmount / $effectiveTotalAmount) * 100, 2) : 0,
            
            // Статусы и проверки
            'is_overdue' => $contract->is_overdue ?? false,
            'is_nearing_limit' => $contract->isNearingLimit() ?? false,
            
            // Дополнительные соглашения
            'agreements' => $agreements,
            'agreements_count' => count($agreements),
            'agreements_total_change' => round(array_sum(array_column($agreements, 'change_amount')), 2),
            
            // Смета
            'estimate' => $estimateInfo,
            
            // Акты выполненных работ
            'performance_acts' => $acts->map(function ($act) {
                return [
                    'id' => $act->id,
                    'act_document_number' => $act->act_document_number,
                    'act_date' => $act->act_date?->format('Y-m-d'),
                    'amount' => round((float) $act->amount, 2),
                    'description' => $act->description,
                    'is_approved' => $act->is_approved,
                    'approval_date' => $act->approval_date?->format('Y-m-d'),
                ];
            })->toArray(),
            'performance_acts_count' => $acts->count(),
            
            // Платежи
            'payments' => $payments->map(function ($payment) {
                $paymentDate = null;
                if (isset($payment->paid_at) && $payment->paid_at) {
                    $paymentDate = $payment->paid_at instanceof \Carbon\Carbon 
                        ? $payment->paid_at->format('Y-m-d') 
                        : (is_string($payment->paid_at) ? \Carbon\Carbon::parse($payment->paid_at)->format('Y-m-d') : null);
                } elseif (isset($payment->updated_at) && $payment->updated_at) {
                    $paymentDate = $payment->updated_at instanceof \Carbon\Carbon 
                        ? $payment->updated_at->format('Y-m-d') 
                        : (is_string($payment->updated_at) ? \Carbon\Carbon::parse($payment->updated_at)->format('Y-m-d') : null);
                }
                
                return [
                    'id' => $payment->id ?? null,
                    'document_number' => $payment->document_number ?? 'N/A',
                    'amount' => round((float) ($payment->paid_amount ?? 0), 2),
                    'payment_date' => $paymentDate,
                    'document_type' => ($payment->invoice_type ?? null) instanceof \BackedEnum 
                        ? $payment->invoice_type->value 
                        : ($payment->invoice_type ?? null),
                    'status' => ($payment->status ?? null) instanceof \BackedEnum 
                        ? $payment->status->value 
                        : ($payment->status ?? null),
                    'payment_purpose' => $payment->payment_purpose ?? null,
                    'notes' => $payment->notes ?? null,
                ];
            })->toArray(),
            'payments_count' => $payments->count(),
            
            // Выполненные работы
            'completed_works' => $completedWorks->map(function ($work) {
                return [
                    'id' => $work->id,
                    'work_type_id' => $work->work_type_id,
                    'work_type_name' => $work->workType?->name ?? 'Не указан',
                    'work_type_code' => $work->workType?->code,
                    'quantity' => round((float) $work->quantity, 3),
                    'price' => round((float) ($work->price ?? 0), 2),
                    'total_amount' => round((float) ($work->total_amount ?? 0), 2),
                    'completion_date' => $work->completion_date?->format('Y-m-d'),
                    'status' => $work->status,
                    'notes' => $work->notes,
                ];
            })->toArray(),
            'completed_works_count' => $completedWorks->count(),
            
            // Статистика по видам работ
            'work_types_statistics' => $workTypesStats,
            
            // Статистика по материалам
            'materials_statistics' => $materialsStats,
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
            'Остаток к выполнению' => 'remaining_to_complete',
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
            'Остаток к выполнению',
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
                $contractor['remaining_to_complete'],
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
            'Предмет контракта',
            'Статус',
            'Категория работ',
            'Дата начала',
            'Дата окончания',
            'Базовая сумма',
            'Сумма контракта',
            'ГП сумма',
            'Гарантийное удержание',
            'Планируемый аванс',
            'Фактический аванс',
            'Выполнено работ',
            'Оплачено',
            'Остаток к доплате',
            'Остаток к выполнению',
            'Процент выполнения',
            'Процент оплаты',
            'Количество актов',
            'Количество платежей',
            'Количество выполненных работ',
            'Количество доп. соглашений',
            'Просрочен',
        ];

        $rows = [];
        foreach ($data['contracts'] as $contract) {
            $rows[] = [
                $contract['contract_number'] ?? '',
                $contract['contract_date'] ?? '',
                $contract['subject'] ?? '',
                $contract['status'] ?? '',
                $contract['work_type_category'] ?? '',
                $contract['start_date'] ?? '',
                $contract['end_date'] ?? '',
                $contract['base_amount'] ?? 0,
                $contract['total_amount'] ?? 0,
                $contract['gp_amount'] ?? 0,
                $contract['warranty_retention_amount'] ?? 0,
                $contract['planned_advance_amount'] ?? '',
                $contract['actual_advance_amount'] ?? '',
                $contract['completed_amount'] ?? 0,
                $contract['payment_amount'] ?? 0,
                $contract['remaining_amount'] ?? 0,
                $contract['remaining_to_complete'] ?? 0,
                ($contract['completion_percentage'] ?? 0) . '%',
                ($contract['payment_percentage'] ?? 0) . '%',
                $contract['performance_acts_count'] ?? 0,
                $contract['payments_count'] ?? 0,
                $contract['completed_works_count'] ?? 0,
                $contract['agreements_count'] ?? 0,
                ($contract['is_overdue'] ?? false) ? 'Да' : 'Нет',
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
            'Остаток к выполнению',
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
                (float)($contractor['remaining_to_complete'] ?? 0),
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
            'Предмет контракта',
            'Статус',
            'Категория работ',
            'Дата начала',
            'Дата окончания',
            'Базовая сумма',
            'Сумма контракта',
            'ГП сумма',
            'Гарантийное удержание',
            'Планируемый аванс',
            'Фактический аванс',
            'Выполнено работ',
            'Оплачено',
            'Остаток к доплате',
            'Остаток к выполнению',
            'Процент выполнения',
            'Процент оплаты',
            'Количество актов',
            'Количество платежей',
            'Количество выполненных работ',
            'Количество доп. соглашений',
            'Просрочен',
        ];

        $rows = [];
        foreach ($data['contracts'] as $contract) {
            $rows[] = [
                (string)($contract['contract_number'] ?? ''),
                (string)($contract['contract_date'] ?? ''),
                (string)($contract['subject'] ?? ''),
                (string)($contract['status'] ?? ''),
                (string)($contract['work_type_category'] ?? ''),
                (string)($contract['start_date'] ?? ''),
                (string)($contract['end_date'] ?? ''),
                (float)($contract['base_amount'] ?? 0),
                (float)($contract['total_amount'] ?? 0),
                (float)($contract['gp_amount'] ?? 0),
                (float)($contract['warranty_retention_amount'] ?? 0),
                (float)($contract['planned_advance_amount'] ?? 0),
                (float)($contract['actual_advance_amount'] ?? 0),
                (float)($contract['completed_amount'] ?? 0),
                (float)($contract['payment_amount'] ?? 0),
                (float)($contract['remaining_amount'] ?? 0),
                (float)($contract['remaining_to_complete'] ?? 0),
                (float)($contract['completion_percentage'] ?? 0),
                (float)($contract['payment_percentage'] ?? 0),
                (int)($contract['performance_acts_count'] ?? 0),
                (int)($contract['payments_count'] ?? 0),
                (int)($contract['completed_works_count'] ?? 0),
                (int)($contract['agreements_count'] ?? 0),
                (bool)($contract['is_overdue'] ?? false) ? 'Да' : 'Нет',
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
     * Для мультипроектных контрактов учитывается только часть суммы относящаяся к проекту.
     */
    private function calculateTotalContractAmount($contracts, ?int $projectId = null): float
    {
        $total = 0;
        foreach ($contracts as $contract) {
            $total += $this->calculateSingleContractAmount($contract, $projectId);
        }
        return $total;
    }

    /**
     * Рассчитать сумму одного контракта с учетом дополнительных соглашений.
     * Для мультипроектных контрактов возвращает часть суммы относящуюся к проекту.
     * 
     * НОВАЯ ЛОГИКА: Использует таблицу allocations если есть явное распределение,
     * иначе fallback на старую логику (пропорционально актам)
     */
    private function calculateSingleContractAmount(Contract $contract, ?int $projectId = null): float
    {
        $fullAmount = (float) ($contract->total_amount ?? 0);
        
        // Если контракт не мультипроектный, возвращаем полную сумму
        if (!$contract->is_multi_project || $projectId === null) {
            return $fullAmount;
        }
        
        // НОВАЯ ЛОГИКА: Проверяем наличие явного распределения
        $allocation = $contract->allocationForProject($projectId);
        
        if ($allocation) {
            return $allocation->calculateAllocatedAmount();
        }
        
        // FALLBACK: Если распределения нет, используем старую логику (пропорционально актам)
        // Для мультипроектных контрактов используем пропорциональное распределение на основе актов
        // Получаем общую сумму всех актов по контракту
        $totalActsAmount = ContractPerformanceAct::where('contract_id', $contract->id)
            ->where('is_approved', true)
            ->sum('amount');
        
        // Если актов нет, распределяем поровну между проектами
        if ($totalActsAmount == 0) {
            $projectsCount = $contract->projects()->count();
            return $projectsCount > 0 ? $fullAmount / $projectsCount : $fullAmount;
        }
        
        // Получаем сумму актов по конкретному проекту
        $projectActsAmount = ContractPerformanceAct::where('contract_id', $contract->id)
            ->where('project_id', $projectId)
            ->where('is_approved', true)
            ->sum('amount');
        
        // Рассчитываем пропорциональную долю
        $proportion = $projectActsAmount / $totalActsAmount;
        
        return $fullAmount * $proportion;
    }

    /**
     * Рассчитать расширенную статистику для отчета.
     */
    private function calculateExtendedSummary($contracts, array $contractsData, ?int $projectId = null): array
    {
        $totalAgreements = 0;
        $totalAgreementsChange = 0;
        $totalPerformanceActs = 0;
        $totalPayments = 0;
        $totalCompletedWorks = 0;
        $overdueContracts = 0;
        $activeContracts = 0;
        $completedContracts = 0;
        $totalGpAmount = 0;
        $totalWarrantyRetention = 0;
        $totalPlannedAdvance = 0;
        $totalActualAdvance = 0;
        $earliestStartDate = null;
        $latestEndDate = null;

        foreach ($contractsData as $contractData) {
            $totalAgreements += $contractData['agreements_count'] ?? 0;
            $totalAgreementsChange += $contractData['agreements_total_change'] ?? 0;
            $totalPerformanceActs += $contractData['performance_acts_count'] ?? 0;
            $totalPayments += $contractData['payments_count'] ?? 0;
            $totalCompletedWorks += $contractData['completed_works_count'] ?? 0;
            $totalGpAmount += $contractData['gp_amount'] ?? 0;
            $totalWarrantyRetention += $contractData['warranty_retention_amount'] ?? 0;
            $totalPlannedAdvance += $contractData['planned_advance_amount'] ?? 0;
            $totalActualAdvance += $contractData['actual_advance_amount'] ?? 0;

            if ($contractData['is_overdue'] ?? false) {
                $overdueContracts++;
            }

            $status = $contractData['status'] ?? '';
            if ($status === 'active' || $status === 'draft') {
                $activeContracts++;
            } elseif ($status === 'completed') {
                $completedContracts++;
            }

            if (isset($contractData['start_date']) && $contractData['start_date']) {
                $startDate = Carbon::parse($contractData['start_date']);
                if (!$earliestStartDate || $startDate->lt($earliestStartDate)) {
                    $earliestStartDate = $startDate;
                }
            }

            if (isset($contractData['end_date']) && $contractData['end_date']) {
                $endDate = Carbon::parse($contractData['end_date']);
                if (!$latestEndDate || $endDate->gt($latestEndDate)) {
                    $latestEndDate = $endDate;
                }
            }
        }

        // Средние значения
        $contractsCount = count($contractsData);
        $avgContractAmount = $contractsCount > 0 ? round(array_sum(array_column($contractsData, 'total_amount')) / $contractsCount, 2) : 0;
        $avgCompletionPercentage = $contractsCount > 0 
            ? round(array_sum(array_column($contractsData, 'completion_percentage')) / $contractsCount, 2) 
            : 0;
        $avgPaymentPercentage = $contractsCount > 0 
            ? round(array_sum(array_column($contractsData, 'payment_percentage')) / $contractsCount, 2) 
            : 0;

        return [
            'total_agreements' => $totalAgreements,
            'total_agreements_change_amount' => round($totalAgreementsChange, 2),
            'total_performance_acts' => $totalPerformanceActs,
            'total_payments_count' => $totalPayments,
            'total_completed_works_count' => $totalCompletedWorks,
            'overdue_contracts_count' => $overdueContracts,
            'active_contracts_count' => $activeContracts,
            'completed_contracts_count' => $completedContracts,
            'total_gp_amount' => round($totalGpAmount, 2),
            'total_warranty_retention_amount' => round($totalWarrantyRetention, 2),
            'total_planned_advance_amount' => round($totalPlannedAdvance, 2),
            'total_actual_advance_amount' => round($totalActualAdvance, 2),
            'remaining_advance_amount' => round($totalPlannedAdvance - $totalActualAdvance, 2),
            'average_contract_amount' => $avgContractAmount,
            'average_completion_percentage' => $avgCompletionPercentage,
            'average_payment_percentage' => $avgPaymentPercentage,
            'earliest_start_date' => $earliestStartDate?->format('Y-m-d'),
            'latest_end_date' => $latestEndDate?->format('Y-m-d'),
            'total_remaining_amount' => round(
                array_sum(array_column($contractsData, 'total_amount')) - 
                array_sum(array_column($contractsData, 'payment_amount')), 
                2
            ),
            'total_remaining_to_complete' => round(
                array_sum(array_column($contractsData, 'total_amount')) - 
                array_sum(array_column($contractsData, 'completed_amount')), 
                2
            ),
        ];
    }

    /**
     * Рассчитать сумму платежей для проекта с учетом мультипроектных контрактов.
     * Для мультиконтрактов платежи распределяются на основе allocations или пропорционально актам.
     * 
     * НОВАЯ ЛОГИКА: Использует allocations для определения доли платежей
     */
    private function calculatePaymentsForProject($payments, $contracts, ?int $projectId = null): float
    {
        if ($payments->isEmpty() || $projectId === null) {
            return $payments->sum('paid_amount') ?? 0;
        }
        
        $totalPaymentAmount = 0;
        
        // Группируем платежи по контрактам
        $paymentsByContract = $payments->groupBy('invoiceable_id');
        
        foreach ($paymentsByContract as $contractId => $contractPayments) {
            $contract = $contracts->firstWhere('id', $contractId);
            
            if (!$contract) {
                // Если контракт не найден, просто суммируем платежи
                $totalPaymentAmount += $contractPayments->sum('paid_amount');
                continue;
            }
            
            $paymentSum = $contractPayments->sum('paid_amount');
            
            // Если контракт не мультипроектный, берем всю сумму платежей
            if (!$contract->is_multi_project) {
                $totalPaymentAmount += $paymentSum;
                continue;
            }
            
            // НОВАЯ ЛОГИКА: Проверяем наличие явного распределения
            $allocation = $contract->allocationForProject($projectId);
            
            if ($allocation) {
                // Если есть allocation, используем его для расчета доли платежей
                $allocatedAmount = $allocation->calculateAllocatedAmount();
                $contractTotal = (float) $contract->total_amount;
                
                if ($contractTotal > 0) {
                    $proportion = $allocatedAmount / $contractTotal;
                    $totalPaymentAmount += $paymentSum * $proportion;
                    continue;
                }
            }
            
            // FALLBACK: Для мультипроектных контрактов распределяем платежи пропорционально актам
            $totalActsAmount = ContractPerformanceAct::where('contract_id', $contract->id)
                ->where('is_approved', true)
                ->sum('amount');
            
            // Если актов нет, распределяем поровну между проектами
            if ($totalActsAmount == 0) {
                $projectsCount = $contract->projects()->count();
                $totalPaymentAmount += $projectsCount > 0 ? $paymentSum / $projectsCount : $paymentSum;
                continue;
            }
            
            // Получаем сумму актов по конкретному проекту
            $projectActsAmount = ContractPerformanceAct::where('contract_id', $contract->id)
                ->where('project_id', $projectId)
                ->where('is_approved', true)
                ->sum('amount');
            
            // Рассчитываем пропорциональную долю платежей
            $proportion = $projectActsAmount / $totalActsAmount;
            $totalPaymentAmount += $paymentSum * $proportion;
        }
        
        return $totalPaymentAmount;
    }

}
