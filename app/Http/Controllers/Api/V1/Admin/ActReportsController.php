<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Http\Requests\Api\V1\Admin\ActReport\PreviewActRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\StoreActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\StoreActFromWizardRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UpdateActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UpdateActWorksRequest;
use App\Models\Contract;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingAvailabilityService;
use App\Services\Acting\ActingPolicyResolver;
use App\Services\Acting\KS3SummaryService;
use App\Services\ActReport\ActReportService;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use App\Http\Responses\AdminResponse;
use App\Exceptions\BusinessLogicException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

/**
 * Контроллер для управления актами выполненных работ
 * 
 * Архитектура: Thin Controller - вся бизнес-логика в ActReportService
 */
class ActReportsController extends Controller
{
    private const PERMISSION_VIEW = 'act_reports.view';
    private const PERMISSION_CREATE = 'act_reports.create';
    private const PERMISSION_MANAGE_WORKS = 'act_reports.works.update';

    public function __construct(
        protected ActReportService $actReportService,
        protected ExcelExporterService $excelExporter,
        protected FileService $fileService,
        protected OfficialFormsExportService $officialExportService,
        protected ActingPolicyResolver $actingPolicyResolver,
        protected ActingAvailabilityService $actingAvailabilityService,
        protected KS3SummaryService $ks3SummaryService,
        protected ActingActWizardService $actingActWizardService
    ) {
        $this->middleware('auth:api_admin');
        $this->middleware('organization.context');
    }

    public function preview(PreviewActRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            $this->authorizePermission($request, self::PERMISSION_VIEW, $organizationId);

            $data = $request->validated();
            $contract = $this->getOrganizationContractOrFail($organizationId, (int) $data['contract_id']);

            return AdminResponse::success([
                'policy' => $this->actingPolicyResolver->resolveForContract($contract),
                'available_works' => $this->actingAvailabilityService->getAvailableWorks(
                    $contract->id,
                    $data['period_start'],
                    $data['period_end']
                ),
                'summary' => $this->ks3SummaryService->summarize(
                    $contract->id,
                    $data['period_start'],
                    $data['period_end']
                ),
            ]);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('act_reports.preview_failed', [
                'contract_id' => $request->input('contract_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.preview_failed'), 500);
        }
    }

    public function createFromWizard(StoreActFromWizardRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            return $this->storeFromWizardPayload($request, $organizationId);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            Log::error('act_reports.create_from_wizard_failed', [
                'contract_id' => $request->input('contract_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.create_failed'), 500);
        }
    }

    /**
     * Получить список актов с фильтрацией и пагинацией
     * 
     * GET /api/v1/admin/act-reports
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            
            $filters = $request->only([
                'contract_id',
                'project_id',
                'contractor_id',
                'is_approved',
                'date_from',
                'date_to',
                'search',
                'sort_by',
                'sort_direction',
            ]);

            $perPage = (int)$request->input('per_page', 15);

            $acts = $this->actReportService->getActsList($organizationId, $filters, $perPage);

            return AdminResponse::success(
                new ContractPerformanceActCollection($acts),
                null
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.load_failed'),
                500
            );
        }
    }

    /**
     * Создать новый акт
     * 
     * POST /api/v1/admin/act-reports
     */
    public function store(StoreActReportRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->getCurrentOrganizationId($request);
            return $this->storeFromWizardPayload($request, $organizationId);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.create_failed'),
                500
            );
        }
    }

    private function storeFromWizardPayload(StoreActFromWizardRequest $request, int $organizationId): JsonResponse
    {
        $this->authorizePermission($request, self::PERMISSION_CREATE, $organizationId);

        $data = $request->validated();
        $manualLines = $data['manual_lines'] ?? [];
        $canManageManualLines = $manualLines === []
            || (bool) $request->user()?->can(self::PERMISSION_MANAGE_WORKS, ['organization_id' => $organizationId]);

        $act = $this->actingActWizardService->createFromWizard(
            $organizationId,
            $data,
            $request->user()?->id,
            $canManageManualLines
        );

        return AdminResponse::success(
            new ContractPerformanceActResource($act),
            trans_message('act_reports.act_created'),
            201
        );
    }

    /**
     * Получить детали акта
     * 
     * GET /api/v1/admin/act-reports/{act}
     */
    public function show(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $act->load([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.user',
                'completedWorks.materials'
            ]);

            return AdminResponse::success(
                new ContractPerformanceActResource($act)
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.load_failed'),
                500
            );
        }
    }

    /**
     * Обновить акт
     * 
     * PUT/PATCH /api/v1/admin/act-reports/{act}
     */
    public function update(UpdateActReportRequest $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $updatedAct = $this->actReportService->updateAct(
                $act,
                $request->validated()
            );

            $updatedAct->load([
                'contract.project',
                'contract.contractor',
                'completedWorks'
            ]);

            return AdminResponse::success(
                new ContractPerformanceActResource($updatedAct),
                trans_message('act_reports.act_updated')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.update_failed'),
                500
            );
        }
    }

    /**
     * Получить доступные работы для добавления в акт
     * 
     * GET /api/v1/admin/act-reports/{act}/available-works
     */
    public function getAvailableWorks(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $availableWorks = $this->actReportService->getAvailableWorks($act);

            if ($availableWorks->isEmpty()) {
                return AdminResponse::success(
                    [],
                    trans_message('act_reports.no_available_works')
                );
            }

            return AdminResponse::success($availableWorks);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.load_failed'),
                500
            );
        }
    }

    /**
     * Обновить работы в акте
     * 
     * PUT /api/v1/admin/act-reports/{act}/works
     */
    public function updateWorks(UpdateActWorksRequest $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $this->actReportService->updateWorksInAct(
                $act,
                $request->validated()['works']
            );

            $act->load([
                'contract',
                'completedWorks.workType',
                'completedWorks.user'
            ]);

            return AdminResponse::success(
                new ContractPerformanceActResource($act),
                trans_message('act_reports.works_updated')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.update_failed'),
                500
            );
        }
    }

    /**
     * Получить ID текущей организации
     */
    protected function getCurrentOrganizationId(Request $request): int
    {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
            throw new BusinessLogicException(
                trans_message('act_reports.organization_not_found'),
                400
            );
        }

        return $organizationId;
    }

    /**
     * Проверить доступ к акту
     */
    protected function authorizeActAccess(Request $request, ContractPerformanceAct $act): void
    {
        $organizationId = $this->getCurrentOrganizationId($request);

            if ($act->contract->organization_id !== $organizationId) {
            throw new BusinessLogicException(
                trans_message('act_reports.access_denied'),
                403
            );
        }
    }

    protected function authorizePermission(Request $request, string $permission, int $organizationId): void
    {
        $user = $request->user();

        if (!$user || !$user->can($permission, ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(
                trans_message('act_reports.access_denied'),
                403
            );
        }
    }

    protected function getOrganizationContractOrFail(int $organizationId, int $contractId): Contract
    {
        $contract = Contract::query()
            ->where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$contract) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_found'), 404);
        }

        return $contract;
    }

    /**
     * Экспорт акта в PDF
     * 
     * GET /api/v1/admin/act-reports/{act}/export/pdf
     */
    public function exportPdf(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $path = $this->officialExportService->exportKS2ToPdf($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (\Throwable $e) {
            Log::error('act_reports.export_pdf_error', [
                'act_id' => $act->id,
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    /**
     * Экспорт акта в Excel
     * 
     * GET /api/v1/admin/act-reports/{act}/export/excel
     */
    public function exportExcel(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $path = $this->officialExportService->exportKS2ToExcel($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (\Throwable $e) {
            Log::error('act_reports.export_excel_error', [
                'act_id' => $act->id,
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    /**
     * Экспорт справки КС-3
     * 
     * GET /api/v1/admin/act-reports/{act}/export/ks3
     */
    public function exportKS3(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->authorizeActAccess($request, $act);

            $path = $this->officialExportService->exportKS3ToExcel($act, $act->contract);
            $url = $this->fileService->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (\Throwable $e) {
            Log::error('act_reports.export_ks3_error', [
                'act_id' => $act->id,
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }
}
