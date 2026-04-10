<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Responses\AdminResponse;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Services\Contract\ContractPerformanceActService;
use App\Services\Storage\FileService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContractPerformanceActController extends Controller
{
    public function __construct(
        protected ContractPerformanceActService $actService,
        protected OfficialFormsExportService $officialExportService,
        protected FileService $fileService
    ) {
    }

    public function index(Request $request, ...$parameters): JsonResponse
    {
        $contractId = $this->getRequiredRouteInt($request, 'contract');
        $projectId = $this->getRouteInt($request, 'project');

        try {
            $acts = $this->actService->getAllActsForContract(
                $contractId,
                $this->getOrganizationId($request),
                $request->only([
                    'is_approved',
                    'date_from',
                    'date_to',
                    'search',
                    'sort_by',
                    'sort_direction',
                ]),
                $projectId
            );

            return AdminResponse::success(new ContractPerformanceActCollection($acts));
        } catch (Throwable $e) {
            Log::error('contract.performance_acts.index_failed', [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.acts_load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreContractPerformanceActRequest $request, ...$parameters): JsonResponse
    {
        $contractId = $this->getRequiredRouteInt($request, 'contract');
        $projectId = $this->getRouteInt($request, 'project');
        $organizationId = $this->getOrganizationId($request);

        try {
            $act = $this->actService->createActForContract(
                $contractId,
                $organizationId,
                $request->toDto(),
                $projectId
            );

            return AdminResponse::success(
                new ContractPerformanceActResource($act),
                trans_message('contract.act_created'),
                Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            Log::error('contract.performance_acts.store_failed', [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.act_create_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Request $request, ...$parameters): JsonResponse
    {
        $actId = $this->getRequiredRouteInt($request, 'performance_act');

        try {
            $act = $this->resolveAct($request, $actId);

            if (!$act) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(new ContractPerformanceActResource($act));
        } catch (Throwable $e) {
            Log::error('contract.performance_acts.show_failed', [
                'act_id' => $actId,
                'contract_id' => $this->getRouteInt($request, 'contract'),
                'project_id' => $this->getRouteInt($request, 'project'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.acts_load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateContractPerformanceActRequest $request, ...$parameters): JsonResponse
    {
        $actId = $this->getRequiredRouteInt($request, 'performance_act');
        $organizationId = $this->getOrganizationId($request);

        try {
            $act = $this->resolveAct($request, $actId);

            if (!$act) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $updatedAct = $this->actService->updateAct(
                $act->id,
                $this->getRouteInt($request, 'contract') ?? (int) $act->contract_id,
                $organizationId,
                $this->buildUpdateDto($request, $act),
                $this->getRouteInt($request, 'project')
            );

            return AdminResponse::success(
                new ContractPerformanceActResource($updatedAct),
                trans_message('contract.act_updated')
            );
        } catch (Exception $e) {
            Log::error('contract.performance_acts.update_failed', [
                'act_id' => $actId,
                'contract_id' => $this->getRouteInt($request, 'contract'),
                'project_id' => $this->getRouteInt($request, 'project'),
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.act_update_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, ...$parameters): JsonResponse
    {
        $actId = $this->getRequiredRouteInt($request, 'performance_act');
        $organizationId = $this->getOrganizationId($request);

        try {
            $act = $this->resolveAct($request, $actId);

            if (!$act) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $deleted = $this->actService->deleteAct(
                $act->id,
                $this->getRouteInt($request, 'contract') ?? (int) $act->contract_id,
                $organizationId,
                $this->getRouteInt($request, 'project')
            );

            if (!$deleted) {
                return AdminResponse::error(trans_message('contract.act_delete_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return AdminResponse::success(null, trans_message('contract.act_deleted'));
        } catch (Exception $e) {
            Log::error('contract.performance_acts.destroy_failed', [
                'act_id' => $actId,
                'contract_id' => $this->getRouteInt($request, 'contract'),
                'project_id' => $this->getRouteInt($request, 'project'),
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.act_delete_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function availableWorks(Request $request, ...$parameters): JsonResponse
    {
        $contractId = $this->getRequiredRouteInt($request, 'contract');
        $projectId = $this->getRouteInt($request, 'project');

        try {
            $works = $this->actService->getAvailableWorksForAct(
                $contractId,
                $this->getOrganizationId($request),
                $projectId
            );

            return AdminResponse::success($works);
        } catch (Exception $e) {
            Log::error('contract.performance_acts.available_works_failed', [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('contract.acts_available_works_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function exportPdf(Request $request, ...$parameters): JsonResponse
    {
        return $this->exportActDocument($request, 'pdf');
    }

    public function exportExcel(Request $request, ...$parameters): JsonResponse
    {
        return $this->exportActDocument($request, 'excel');
    }

    public function exportKS3(Request $request, ...$parameters): JsonResponse
    {
        return $this->exportActDocument($request, 'ks3');
    }

    public function getFiles(Request $request, ...$parameters): JsonResponse
    {
        $actId = $this->getRequiredRouteInt($request, 'performance_act');

        try {
            $organizationId = $this->getOrganizationId($request);
            $act = ContractPerformanceAct::with(['contract', 'files.user'])->find($actId);

            if (!$act || (int) $act->contract->organization_id !== $organizationId) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $organization = Organization::find($organizationId);

            if (!$organization) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
            }

            $disk = $this->fileService->disk($organization);
            $files = $act->files->map(function ($file) use ($disk) {
                $downloadUrl = null;

                try {
                    if ($disk->exists($file->path)) {
                        $downloadUrl = $disk->temporaryUrl($file->path, now()->addHours(1));
                    }
                } catch (Throwable $e) {
                    Log::warning('contract.performance_acts.file_temporary_url_failed', [
                        'file_id' => $file->id,
                        'path' => $file->path,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                    'category' => $file->category,
                    'uploaded_by' => $file->user?->name,
                    'uploaded_at' => $file->created_at?->toIso8601String(),
                    'description' => $file->additional_info['description'] ?? null,
                    'download_url' => $downloadUrl,
                ];
            });

            return AdminResponse::success($files);
        } catch (Throwable $e) {
            Log::error('contract.performance_acts.files_failed', [
                'act_id' => $actId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.act_files_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function exportActDocument(Request $request, string $format): JsonResponse
    {
        $actId = $this->getRequiredRouteInt($request, 'performance_act');

        try {
            $act = $this->resolveAct($request, $actId);

            if (!$act) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $path = match ($format) {
                'pdf' => $this->officialExportService->exportKS2ToPdf($act, $act->contract),
                'excel' => $this->officialExportService->exportKS2ToExcel($act, $act->contract),
                'ks3' => $this->officialExportService->exportKS3ToExcel($act, $act->contract),
            };

            $url = $this->officialExportService->getFileService()->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (Throwable $e) {
            Log::error('contract.performance_acts.export_failed', [
                'act_id' => $actId,
                'contract_id' => $this->getRouteInt($request, 'contract'),
                'project_id' => $this->getRouteInt($request, 'project'),
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.act_export_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function resolveAct(Request $request, int $actId): ?ContractPerformanceAct
    {
        $organizationId = $this->getOrganizationId($request);
        $contractId = $this->getRouteInt($request, 'contract');
        $projectId = $this->getRouteInt($request, 'project');

        $act = ContractPerformanceAct::with([
            'contract',
            'completedWorks.workType',
            'completedWorks.user',
            'files.user',
        ])->find($actId);

        if (!$act || !$act->contract) {
            return null;
        }

        if ((int) $act->contract->organization_id !== $organizationId) {
            return null;
        }

        if ($contractId !== null && (int) $act->contract_id !== $contractId) {
            return null;
        }

        if ($projectId !== null && (int) $act->project_id !== $projectId) {
            return null;
        }

        return $act;
    }

    protected function getOrganizationId(Request $request): int
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->organization_id ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new Exception(trans_message('contract.organization_context_missing'));
        }

        return (int) $organizationId;
    }

    protected function getRouteInt(Request $request, string $key): ?int
    {
        $value = $request->route($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && isset($value->id)) {
            return (int) $value->id;
        }

        return (int) $value;
    }

    protected function getRequiredRouteInt(Request $request, string $key): int
    {
        $value = $this->getRouteInt($request, $key);

        if ($value === null) {
            throw new Exception("Route parameter [{$key}] is required.");
        }

        return $value;
    }

    protected function buildUpdateDto(
        UpdateContractPerformanceActRequest $request,
        ContractPerformanceAct $act
    ): ContractPerformanceActDTO {
        $validated = $request->validated();

        return new ContractPerformanceActDTO(
            project_id: $this->getRouteInt($request, 'project') ?? (int) $act->project_id,
            act_document_number: $validated['act_document_number'] ?? $act->act_document_number,
            act_date: $validated['act_date'] ?? $act->act_date,
            description: $validated['description'] ?? $act->description,
            is_approved: array_key_exists('is_approved', $validated)
                ? (bool) $validated['is_approved']
                : (bool) $act->is_approved,
            approval_date: $validated['approval_date'] ?? $act->approval_date,
            completed_works: $validated['completed_works'] ?? [],
            amount: isset($validated['amount']) ? (float) $validated['amount'] : (float) $act->amount
        );
    }
}
