<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Responses\AdminResponse;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class PerformanceActReportController extends Controller
{
    public function __construct(
        protected ExcelExporterService $excelExporter,
        protected OfficialFormsExportService $officialExportService,
        protected FileService $fileService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(
                    trans_message('performance_acts.organization_not_found'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $acts = $this->buildQuery($request, $organizationId)->paginate((int) $request->get('per_page', 15));

            return $this->paginatedResponse($acts, $organizationId);
        } catch (Exception $e) {
            Log::error('performance_act_report.index.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('performance_acts.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function show(int $actId): JsonResponse
    {
        try {
            $act = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.executor',
            ])->findOrFail($actId);

            return AdminResponse::success(new ContractPerformanceActResource($act));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('performance_acts.not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (Exception $e) {
            Log::error('performance_act_report.show.error', [
                'act_id' => $actId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('performance_acts.details_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function exportPdf(int $actId): JsonResponse
    {
        try {
            $act = ContractPerformanceAct::with('contract')->findOrFail($actId);
            $path = $this->officialExportService->exportKS2ToPdf($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('performance_acts.not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (Exception $e) {
            Log::error('performance_act_report.export_pdf.error', [
                'act_id' => $actId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('performance_acts.export_pdf_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function exportExcel(int $actId): JsonResponse
    {
        try {
            $act = ContractPerformanceAct::with('contract')->findOrFail($actId);
            $path = $this->officialExportService->exportKS2ToExcel($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('performance_acts.not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (Exception $e) {
            Log::error('performance_act_report.export_excel.error', [
                'act_id' => $actId,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('performance_acts.export_excel_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function bulkExportExcel(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(
                    trans_message('performance_acts.organization_not_found'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $actIds = $request->input('act_ids', []);
            if ($actIds === []) {
                return AdminResponse::error(
                    trans_message('performance_acts.bulk_export_validation_error'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $acts = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType.measurementUnit',
            ])->whereHas('contract', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
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
            ];

            $exportData = [];
            foreach ($acts as $act) {
                foreach ($act->completedWorks as $work) {
                    $exportData[] = [
                        $act->act_document_number,
                        $act->contract->number ?? $act->contract->contract_number ?? '',
                        $act->contract->project->name ?? '',
                        $act->contract->contractor->name ?? '',
                        $act->act_date ? $act->act_date->format('d.m.Y') : '',
                        number_format((float) $act->amount, 2, '.', ''),
                        $act->is_approved ? 'Утвержден' : 'Не утвержден',
                        $work->workType?->name ?? $work->description,
                        $work->workType?->measurementUnit?->short_name ?? '',
                        $work->pivot?->included_quantity ?? $work->quantity,
                        $work->pivot?->included_amount
                            ? ($work->pivot->included_amount / ($work->pivot->included_quantity ?: 1))
                            : $work->unit_price,
                        $work->pivot?->included_amount ?? $work->total_amount,
                    ];
                }
            }

            $org = Organization::find($organizationId);
            if (!$org) {
                return AdminResponse::error(
                    trans_message('performance_acts.organization_not_found'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $filename = 'bulk_acts_' . now()->format('Ymd_His') . '.xlsx';
            $spreadsheet = $this->excelExporter->createSpreadsheet($headers, $exportData);

            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean();

            $s3Path = "org-{$organizationId}/exports/bulk_acts/{$filename}";
            $this->fileService->disk($org)->put($s3Path, $content);
            $url = $this->fileService->temporaryUrl($s3Path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (Exception $e) {
            Log::error('performance_act_report.bulk_export_excel.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('performance_acts.bulk_export_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function buildQuery(Request $request, int $organizationId): Builder
    {
        $query = ContractPerformanceAct::with([
            'contract.project',
            'contract.contractor',
            'completedWorks',
        ])->whereHas('contract', function ($builder) use ($organizationId) {
            $builder->where('organization_id', $organizationId);
        });

        if ($request->filled('contract_id')) {
            $query->where('contract_id', $request->contract_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('contractor_id')) {
            $query->whereHas('contract', function ($builder) use ($request) {
                $builder->where('contractor_id', $request->contractor_id);
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
            $query->where(function ($builder) use ($search) {
                $builder->where('act_document_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('contract', function ($contractQuery) use ($search) {
                        $contractQuery->where('contract_number', 'like', "%{$search}%")
                            ->orWhere('number', 'like', "%{$search}%");
                    });
            });
        }

        return $query->orderBy(
            $request->get('sort_by', 'act_date'),
            $request->get('sort_direction', 'desc')
        );
    }

    private function paginatedResponse(LengthAwarePaginator $acts, int $organizationId): JsonResponse
    {
        return AdminResponse::paginated(
            ContractPerformanceActResource::collection($acts->getCollection())->resolve(),
            [
                'current_page' => $acts->currentPage(),
                'last_page' => $acts->lastPage(),
                'per_page' => $acts->perPage(),
                'total' => $acts->total(),
                'from' => $acts->firstItem(),
                'to' => $acts->lastItem(),
            ],
            null,
            Response::HTTP_OK,
            [
                'total_acts' => ContractPerformanceAct::whereHas('contract', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                })->count(),
                'approved_acts' => ContractPerformanceAct::whereHas('contract', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                })->where('is_approved', true)->count(),
                'total_amount' => ContractPerformanceAct::whereHas('contract', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                })->sum('amount'),
            ],
            [
                'first' => $acts->url(1),
                'last' => $acts->url($acts->lastPage()),
                'prev' => $acts->previousPageUrl(),
                'next' => $acts->nextPageUrl(),
            ]
        );
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $user = $request->user();

        return $user?->organization_id ?? $user?->current_organization_id;
    }
}
