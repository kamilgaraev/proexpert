<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Http\Requests\Api\V1\Admin\ActReport\StoreActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UpdateActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UpdateActWorksRequest;
use App\Services\ActReport\ActReportService;
use App\Services\Export\ExcelExporterService;
use App\Services\Storage\FileService;
use App\Http\Responses\AdminResponse;
use App\Exceptions\BusinessLogicException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
    public function __construct(
        protected ActReportService $actReportService,
        protected ExcelExporterService $excelExporter,
        protected FileService $fileService
    ) {
        $this->middleware('auth:api_admin');
        $this->middleware('organization.context');
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
            
            $act = $this->actReportService->createAct(
                $organizationId,
                $request->validated()
            );

            return AdminResponse::success(
                new ContractPerformanceActResource($act),
                trans_message('act_reports.act_created'),
                201
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            return AdminResponse::error(
                trans_message('act_reports.create_failed'),
                500
            );
        }
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

    // ================================================================
    // TODO: Методы экспорта (PDF, Excel) будут добавлены в следующем блоке
    // TODO: Методы работы с файлами будут добавлены в следующем блоке
    // ================================================================
}
