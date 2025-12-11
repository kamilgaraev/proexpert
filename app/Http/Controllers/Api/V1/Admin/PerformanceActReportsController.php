<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractPerformanceAct;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Services\Export\ExcelExporterService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Exception;

class PerformanceActReportsController extends Controller
{
    protected ExcelExporterService $excelExporter;

    public function __construct(ExcelExporterService $excelExporter)
    {
        $this->excelExporter = $excelExporter;
    }

    /**
     * Получить все акты организации с фильтрацией
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json([
                    'error' => 'Не определена организация пользователя'
                ], 400);
            }

            $query = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor', 
                'completedWorks'
            ])->whereHas('contract', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });

            // Фильтры
            if ($request->filled('contract_id')) {
                $query->where('contract_id', $request->contract_id);
            }

            if ($request->filled('project_id')) {
                $projectId = $request->project_id;
                $query->whereHas('contract', function ($q) use ($projectId) {
                    // Ищем акты как по обычным контрактам, так и по мультипроектным
                    $q->where(function($subQ) use ($projectId) {
                        // Обычные контракты (поле project_id)
                        $subQ->where('project_id', $projectId)
                            // ИЛИ мультипроектные контракты (через pivot таблицу contract_project)
                            ->orWhereExists(function($existsQ) use ($projectId) {
                                $existsQ->select(\DB::raw(1))
                                    ->from('contract_project')
                                    ->whereColumn('contract_project.contract_id', 'contracts.id')
                                    ->where('contract_project.project_id', $projectId);
                            });
                    });
                });
            }

            if ($request->filled('contractor_id')) {
                $query->whereHas('contract', function ($q) use ($request) {
                    $q->where('contractor_id', $request->contractor_id);
                });
            }

            if ($request->filled('is_approved')) {
                $query->where('is_approved', $request->boolean('is_approved'));
            }

            if ($request->filled('date_from')) {
                $query->where('act_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('act_date', '<=', $request->date_to);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('act_document_number', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereHas('contract', function ($contractQuery) use ($search) {
                          $contractQuery->where('contract_number', 'like', "%{$search}%");
                      });
                });
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'act_date');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // Пагинация
            $perPage = $request->get('per_page', 15);
            $acts = $query->paginate($perPage);

            return response()->json([
                'data' => new ContractPerformanceActCollection($acts),
                'pagination' => [
                    'current_page' => $acts->currentPage(),
                    'last_page' => $acts->lastPage(),
                    'per_page' => $acts->perPage(),
                    'total' => $acts->total(),
                ],
                'statistics' => [
                    'total_acts' => ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId);
                    })->count(),
                    'approved_acts' => ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId);
                    })->where('is_approved', true)->count(),
                    'total_amount' => ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId);
                    })->sum('amount'),
                ],
                'message' => 'Акты получены успешно'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при получении актов',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить детали акта
     */
    public function show(int $actId): JsonResponse
    {
        try {
            $act = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.executor'
            ])->findOrFail($actId);

            return response()->json([
                'data' => new ContractPerformanceActResource($act),
                'message' => 'Акт получен успешно'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при получении акта',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Экспорт акта в PDF
     */
    public function exportPdf(int $actId)
    {
        try {
            $act = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.executor'
            ])->findOrFail($actId);

            $data = [
                'act' => $act,
                'contract' => $act->contract,
                'project' => $act->contract->project,
                'contractor' => $act->contract->contractor,
                'works' => $act->completedWorks,
                'total_amount' => $act->amount,
                'generated_at' => now()->format('d.m.Y H:i')
            ];

            $pdf = Pdf::loadView('reports.act-report-pdf', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = "act_{$act->act_document_number}_" . now()->format('Y-m-d') . ".pdf";

            return $pdf->download($filename);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при экспорте в PDF',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Экспорт акта в Excel
     */
    public function exportExcel(int $actId)
    {
        try {
            $act = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.executor'
            ])->findOrFail($actId);

            $headers = [
                'Наименование работы',
                'Единица измерения',
                'Количество',
                'Цена за единицу',
                'Сумма',
                'Материалы',
                'Дата выполнения',
                'Исполнитель'
            ];

            $exportData = [];
            foreach ($act->completedWorks as $work) {
                $materials = '';
                if ($work->materials && $work->materials->isNotEmpty()) {
                    $materials = $work->materials->map(function ($material) {
                        $quantity = $material->pivot->quantity ?? 0;
                        $unit = $material->unit ?? '';
                        return $material->name . ' (' . $quantity . ' ' . $unit . ')';
                    })->join(', ');
                }

                $workTypeName = $work->workType ? $work->workType->name : 'Не указан';
                $executorName = $work->executor ? $work->executor->name : 'Не указан';
                $completionDate = $work->completion_date ? $work->completion_date->format('d.m.Y') : 'Не указана';

                $exportData[] = [
                    $workTypeName,
                    $work->unit ?? '',
                    number_format($work->quantity ?? 0, 2, ',', ' '),
                    number_format($work->unit_price ?? 0, 2, ',', ' '),
                    number_format($work->total_amount ?? 0, 2, ',', ' '),
                    $materials,
                    $completionDate,
                    $executorName
                ];
            }

            $filename = "act_{$act->act_document_number}_" . now()->format('Y-m-d') . ".xlsx";

            return $this->excelExporter->streamDownload($filename, $headers, $exportData);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при экспорте в Excel',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Массовый экспорт актов в Excel
     */
    public function bulkExportExcel(Request $request)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            $actIds = $request->input('act_ids', []);
            
            if (empty($actIds)) {
                return response()->json([
                    'error' => 'Не выбраны акты для экспорта'
                ], 400);
            }

            $acts = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.executor'
            ])->whereHas('contract', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->whereIn('id', $actIds)->get();

            $headers = [
                'Номер акта',
                'Контракт',
                'Проект',
                'Подрядчик',
                'Дата акта',
                'Сумма',
                'Статус',
                'Наименование работы',
                'Единица измерения',
                'Количество',
                'Цена за единицу',
                'Сумма работы',
                'Материалы',
                'Дата выполнения',
                'Исполнитель'
            ];

            $exportData = [];
            foreach ($acts as $act) {
                foreach ($act->completedWorks as $work) {
                    $materials = '';
                    if ($work->materials && $work->materials->isNotEmpty()) {
                        $materials = $work->materials->map(function ($material) {
                            $quantity = $material->pivot->quantity ?? 0;
                            $unit = $material->unit ?? '';
                            return $material->name . ' (' . $quantity . ' ' . $unit . ')';
                        })->join(', ');
                    }

                    $workTypeName = $work->workType ? $work->workType->name : 'Не указан';
                    $executorName = $work->executor ? $work->executor->name : 'Не указан';
                    $completionDate = $work->completion_date ? $work->completion_date->format('d.m.Y') : 'Не указана';

                    $exportData[] = [
                        $act->act_document_number,
                        $act->contract->contract_number ?? '',
                        $act->contract->project->name ?? '',
                        $act->contract->contractor->name ?? '',
                        $act->act_date ? $act->act_date->format('d.m.Y') : '',
                        number_format($act->amount ?? 0, 2, ',', ' '),
                        $act->is_approved ? 'Утвержден' : 'Не утвержден',
                        $workTypeName,
                        $work->unit ?? '',
                        number_format($work->quantity ?? 0, 2, ',', ' '),
                        number_format($work->unit_price ?? 0, 2, ',', ' '),
                        number_format($work->total_amount ?? 0, 2, ',', ' '),
                        $materials,
                        $completionDate,
                        $executorName
                    ];
                }
            }

            $filename = "acts_bulk_export_" . now()->format('Y-m-d_H-i-s') . ".xlsx";

            return $this->excelExporter->streamDownload($filename, $headers, $exportData);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при массовом экспорте',
                'message' => $e->getMessage()
            ], 500);
        }
    }
} 