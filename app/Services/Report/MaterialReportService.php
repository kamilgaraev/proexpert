<?php

namespace App\Services\Report;

use App\Models\Project;
use App\Models\Organization;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\MaterialReceipt;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Services\RateCoefficient\RateCoefficientService;
use App\Enums\RateCoefficient\RateCoefficientAppliesToEnum;

class MaterialReportService
{
    private RateCoefficientService $rateCoefficientService;

    public function __construct(RateCoefficientService $rateCoefficientService)
    {
        $this->rateCoefficientService = $rateCoefficientService;
    }

    /**
     * Генерирует данные для официального отчета об использовании материалов.
     */
    public function generateOfficialUsageReport(
        int $projectId,
        string $dateFrom,
        string $dateTo,
        ?int $reportNumber = null,
        array $filters = []
    ): array {
        $project = Project::with(['organization'])->findOrFail($projectId);
        $periodFrom = Carbon::parse($dateFrom);
        $periodTo = Carbon::parse($dateTo);
        
        Log::info('Generating official material usage report', [
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'report_number' => $reportNumber
        ]);

        // Получаем все операции с материалами за период с применением фильтров
        $materialLogsQuery = MaterialUsageLog::where('project_id', $projectId)
            ->whereBetween('usage_date', [$periodFrom, $periodTo])
            ->with(['material.measurementUnit', 'user', 'supplier', 'workType']);

        $this->applyFiltersToMaterialLogs($materialLogsQuery, $filters);
        $materialLogs = $materialLogsQuery->get();

        // Получаем приходы за период и предыдущие с применением фильтров
        $receiptsQuery = MaterialReceipt::where('project_id', $projectId)
            ->where('receipt_date', '<=', $periodTo)
            ->with(['material.measurementUnit', 'supplier']);

        $this->applyFiltersToReceipts($receiptsQuery, $filters);
        $receipts = $receiptsQuery->get();

        // Группируем по материалам и видам работ
        $materialGroups = $this->groupMaterialsByWork($materialLogs, $receipts, $periodFrom, $periodTo, $project->organization_id, $projectId);

        if (empty($materialGroups)) {
            Log::warning('Official usage report: no material data', ['project_id' => $projectId]);
            return [
                'header' => [
                    'report_date' => now()->format('d.m.Y'),
                    'project_name' => $project->name,
                ],
                'message' => 'Нет данных за выбранный период',
                'materials' => [],
            ];
        }

        return [
            'header' => [
                'report_number' => $reportNumber ?? 1,
                'report_date' => now()->format('d.m.Y'),
                'period_from' => $periodFrom->format('d.m.Y'),
                'period_to' => $periodTo->format('d.m.Y'),
                'project_name' => $project->name,
                'project_address' => $project->address,
            ],
            'organizations' => [
                'contractor' => $project->organization->name,
                'contractor_director' => $this->getDirectorName($project->organization),
                'customer' => $project->customer_organization ?? $project->customer ?? 'Заказчик не указан',
                'customer_representative' => $project->customer_representative ?? 'Не указан',
                'contract_number' => $project->contract_number,
                'contract_date' => $project->contract_date?->format('d.m.Y'),
            ],
            'materials' => $materialGroups,
            'summary' => $this->calculateSummary($materialGroups),
            'generated_at' => now(),
        ];
    }

    /**
     * Группирует материалы по видам работ.
     */
    private function groupMaterialsByWork(
        Collection $materialLogs,
        Collection $receipts,
        Carbon $periodFrom,
        Carbon $periodTo,
        int $organizationId,
        int $projectId
    ): array {
        $grouped = [];
        
        // Группируем по работам и материалам
        $workGroups = $materialLogs->groupBy(function ($log) {
            return $log->work_description ?? $log->workType?->name ?? 'Общие материалы';
        });

        foreach ($workGroups as $workName => $workLogs) {
            $materialGroups = $workLogs->groupBy('material_id');
            
            foreach ($materialGroups as $materialId => $logs) {
                $material = $logs->first()->material;
                $unit = $material->measurementUnit->short_name ?? 'шт';
                
                // Получаем приходы для этого материала
                $materialReceipts = $receipts->where('material_id', $materialId);
                
                // Расчеты по материалу
                $receivedQuantity = $materialReceipts->sum('quantity');
                $receivedDocs = $materialReceipts->map(function ($receipt) {
                    return "№{$receipt->document_number} от {$receipt->receipt_date->format('d.m.Y')}";
                })->implode(', ');
                
                $usedQuantity = $logs->where('operation_type', 'write_off')->sum('quantity');
                $normQuantity = $logs->sum('production_norm_quantity') ?: $usedQuantity;

                // Корректируем норму с учётом коэффициентов организации и получаем детали расчёта
                $coeffResult = $this->rateCoefficientService->calculateAdjustedValueDetailed(
                    $organizationId,
                    $normQuantity,
                    RateCoefficientAppliesToEnum::MATERIAL_NORMS->value,
                    null,
                    ['project_id' => $projectId, 'material_id' => $materialId]
                );

                $normQuantity = $coeffResult['final'];
                
                $previousBalance = $logs->first()->previous_month_balance ?? 0;
                $currentBalance = $receivedQuantity + $previousBalance - $usedQuantity;
                
                $economyOverrun = $normQuantity - $usedQuantity;

                $grouped[] = [
                    'work_name' => $workName,
                    'material_name' => $material->name,
                    'unit' => $unit,
                    'received_from_customer' => [
                        'volume' => $receivedQuantity,
                        'document' => $receivedDocs ?: 'Документы не указаны',
                    ],
                    'usage' => [
                        'production_norm' => $normQuantity,
                        'fact_used' => $usedQuantity,
                        'balance' => $currentBalance,
                        'for_next_month' => max(0, $currentBalance),
                    ],
                    'economy_overrun' => $economyOverrun,
                    'economy_percentage' => $normQuantity > 0 ? ($economyOverrun / $normQuantity) * 100 : 0,
                    'coefficients_applied' => $coeffResult['applications'],
                ];
            }
        }

        return $grouped;
    }

    /**
     * Рассчитывает итоговые показатели.
     */
    private function calculateSummary(array $materials): array
    {
        $totalEconomy = collect($materials)->sum('economy_overrun');
        $totalNorm = collect($materials)->sum('usage.production_norm');
        
        return [
            'total_materials_count' => count($materials),
            'total_economy' => $totalEconomy,
            'average_economy_percentage' => $totalNorm > 0 ? ($totalEconomy / $totalNorm) * 100 : 0,
        ];
    }

    /**
     * Получает имя директора организации.
     */
    private function getDirectorName(Organization $organization): string
    {
        // Можно добавить поле director_name в Organization или получать из связанных пользователей
        return 'Директор не указан'; // Заглушка
    }

    /**
     * Применяет фильтры к запросу логов материалов.
     */
    private function applyFiltersToMaterialLogs($query, array $filters): void
    {
        if (!empty($filters['material_id'])) {
            $query->where('material_id', $filters['material_id']);
        }

        if (!empty($filters['material_name'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['material_name'] . '%');
            });
        }

        if (!empty($filters['operation_type'])) {
            $query->where('operation_type', $filters['operation_type']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['document_number'])) {
            $query->where('document_number', 'like', '%' . $filters['document_number'] . '%');
        }

        if (!empty($filters['work_type_id'])) {
            $query->where('work_type_id', $filters['work_type_id']);
        }

        if (!empty($filters['work_description'])) {
            $query->where('work_description', 'like', '%' . $filters['work_description'] . '%');
        }

        if (!empty($filters['user_id']) || !empty($filters['foreman_id'])) {
            $userId = $filters['user_id'] ?? $filters['foreman_id'];
            $query->where('user_id', $userId);
        }

        if (!empty($filters['invoice_date_from']) && !empty($filters['invoice_date_to'])) {
            $query->whereBetween('invoice_date', [$filters['invoice_date_from'], $filters['invoice_date_to']]);
        } elseif (!empty($filters['invoice_date_from'])) {
            $query->where('invoice_date', '>=', $filters['invoice_date_from']);
        } elseif (!empty($filters['invoice_date_to'])) {
            $query->where('invoice_date', '<=', $filters['invoice_date_to']);
        }

        if (!empty($filters['min_quantity'])) {
            $query->where('quantity', '>=', $filters['min_quantity']);
        }

        if (!empty($filters['max_quantity'])) {
            $query->where('quantity', '<=', $filters['max_quantity']);
        }

        if (!empty($filters['min_price'])) {
            $query->where('unit_price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('unit_price', '<=', $filters['max_price']);
        }

        if (isset($filters['has_photo'])) {
            if ($filters['has_photo']) {
                $query->whereNotNull('photo_path');
            } else {
                $query->whereNull('photo_path');
            }
        }
    }

    /**
     * Применяет фильтры к запросу поступлений материалов.
     */
    private function applyFiltersToReceipts($query, array $filters): void
    {
        if (!empty($filters['material_id'])) {
            $query->where('material_id', $filters['material_id']);
        }

        if (!empty($filters['material_name'])) {
            $query->whereHas('material', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['material_name'] . '%');
            });
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['document_number'])) {
            $query->where('document_number', 'like', '%' . $filters['document_number'] . '%');
        }

        if (!empty($filters['user_id']) || !empty($filters['foreman_id'])) {
            $userId = $filters['user_id'] ?? $filters['foreman_id'];
            $query->where('user_id', $userId);
        }

        if (!empty($filters['min_quantity'])) {
            $query->where('quantity', '>=', $filters['min_quantity']);
        }

        if (!empty($filters['max_quantity'])) {
            $query->where('quantity', '<=', $filters['max_quantity']);
        }

        if (!empty($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }
    }
} 