<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ContractPerformanceAct;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use App\Http\Responses\AdminResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PerformanceActReportsController extends Controller
{
    public function __construct(
        protected ExcelExporterService $excelExporter,
        protected OfficialFormsExportService $officialExportService,
        protected FileService $fileService
    ) {}

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

            // Фильтруем акты напрямую по project_id
            if ($request->filled('project_id')) {
                $query->where('project_id', $request->project_id);
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
    public function exportPdf(int $actId): JsonResponse
    {
        try {
            $act = ContractPerformanceAct::with('contract')->findOrFail($actId);
            
            $path = $this->officialExportService->exportKS2ToPdf($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (Exception $e) {
            Log::error('PerformanceActReports.exportPdf failed', [
                'act_id' => $actId,
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error('Ошибка при экспорте в PDF: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Экспорт акта в Excel
     */
    public function exportExcel(int $actId): JsonResponse
    {
        try {
            $act = ContractPerformanceAct::with('contract')->findOrFail($actId);
            
            $path = $this->officialExportService->exportKS2ToExcel($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (Exception $e) {
            Log::error('PerformanceActReports.exportExcel failed', [
                'act_id' => $actId,
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error('Ошибка при экспорте в Excel: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Массовый экспорт актов в Excel
     */
    public function bulkExportExcel(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            $actIds = $request->input('act_ids', []);
            
            if (empty($actIds)) {
                return AdminResponse::error('Не выбраны акты для экспорта', 400);
            }

            $acts = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType',
            ])->whereHas('contract', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->whereIn('id', $actIds)->get();

            $headers = [
                'Номер акта', 'Контракт', 'Проект', 'Подрядчик', 'Дата акта', 'Сумма', 'Статус',
                'Наименование работы', 'Единица измерения', 'Количество', 'Цена за единицу', 'Сумма работы'
            ];

            $exportData = [];
            foreach ($acts as $act) {
                foreach ($act->completedWorks as $work) {
                    $exportData[] = [
                        $act->act_document_number,
                        $act->contract->number ?? '',
                        $act->contract->project->name ?? '',
                        $act->contract->contractor->name ?? '',
                        $act->act_date ? $act->act_date->format('d.m.Y') : '',
                        number_format((float)$act->amount, 2, '.', ''),
                        $act->is_approved ? 'Утвержден' : 'Не утвержден',
                        $work->workType?->name ?? $work->description,
                        $work->workType?->measurementUnit?->short_name ?? '',
                        $work->pivot?->included_quantity ?? $work->quantity,
                        $work->pivot?->included_amount ? ($work->pivot->included_amount / ($work->pivot->included_quantity ?: 1)) : $work->unit_price,
                        $work->pivot?->included_amount ?? $work->total_amount
                    ];
                }
            }

            $filename = "bulk_acts_" . now()->format('Ymd_His') . ".xlsx";
            $spreadsheet = $this->excelExporter->createSpreadsheet($headers, $exportData);
            
            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean();
            
            $path = "exports/bulk_acts/{$filename}";
            $org = Organization::find($organizationId);
            $s3Path = "org-{$organizationId}/{$path}";
            
            $this->fileService->disk($org)->put($s3Path, $content);
            $url = $this->fileService->temporaryUrl($s3Path, 15);

            return AdminResponse::success(['url' => $url]);

        } catch (Exception $e) {
            Log::error('PerformanceActReports.bulkExportExcel failed', [
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error('Ошибка при массовом экспорте: ' . $e->getMessage(), 500);
        }
    }
}