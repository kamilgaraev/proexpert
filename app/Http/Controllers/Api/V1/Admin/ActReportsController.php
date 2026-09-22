<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ActingQuantityConflictException;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ActReport\AnnulActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\ApproveContractPeriodCertificateRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\BulkExportActReportsRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\CreateContractPeriodCertificateRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\PreviewActRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\RejectActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\StoreActFromWizardRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\StoreActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UpdateActReportRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UploadActReportFileRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UploadSignedActFileRequest;
use App\Http\Requests\Api\V1\Admin\ActReport\UploadSignedCertificateFileRequest;
use App\Http\Resources\Api\V1\Admin\ActReport\ContractPeriodCertificateResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Responses\AdminResponse;
use App\Models\ContractPerformanceAct;
use App\Models\ContractPeriodCertificate;
use App\Services\Acting\ContractPeriodCertificateService;
use App\Services\ActReport\ActReportAccessService;
use App\Services\ActReport\ActReportExportService;
use App\Services\ActReport\ActReportFileService;
use App\Services\ActReport\ActReportService;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function trans_message;

class ActReportsController extends Controller
{
    public function __construct(
        protected ActReportService $actReportService,
        protected ActReportWorkflowService $workflowService,
        protected ActReportAccessService $accessService,
        protected ActReportFileService $fileService,
        protected ActReportExportService $exportService,
        protected ContractPeriodCertificateService $certificateService
    ) {
        $this->middleware('auth:api_admin');
        $this->middleware('organization.context');
    }

    public function createPeriodCertificate(CreateContractPeriodCertificateRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_CREATE, $organizationId);
            $data = $request->validated();
            $contract = $this->accessService->findAccessibleContractOrFail($organizationId, (int) $data['contract_id']);
            $certificate = $this->certificateService->create(
                $contract,
                $data['period_start'],
                $data['period_end'],
                isset($data['project_id']) ? (int) $data['project_id'] : null,
                $data['idempotency_key'],
                (int) $request->user()?->id,
                $data['act_ids'] ?? null,
                $data['document_date'] ?? null,
            );

            return AdminResponse::success(
                new ContractPeriodCertificateResource($certificate),
                trans_message('act_reports.certificate_created'),
                201
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_create_failed', [
                'contract_id' => $request->input('contract_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.create_failed'), 500);
        }
    }

    public function showPeriodCertificate(Request $request, mixed $certificate): JsonResponse
    {
        try {
            $certificate = $this->resolveCertificate($request, $certificate, ActReportAccessService::PERMISSION_VIEW);

            return AdminResponse::success(new ContractPeriodCertificateResource($certificate->load('memberships')));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_show_failed', [
                'certificate_id' => is_object($certificate) ? ($certificate->id ?? null) : $certificate,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.load_failed'), 500);
        }
    }

    public function approvePeriodCertificate(ApproveContractPeriodCertificateRequest $request, mixed $certificate): JsonResponse
    {
        try {
            $certificate = $this->resolveCertificate($request, $certificate, ActReportAccessService::PERMISSION_APPROVE);

            return AdminResponse::success(
                new ContractPeriodCertificateResource(
                    $this->certificateService->approve($certificate, (int) $request->user()?->id)
                ),
                trans_message('act_reports.certificate_approved')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_approve_failed', [
                'certificate_id' => is_object($certificate) ? ($certificate->id ?? null) : $certificate,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.update_failed'), 500);
        }
    }

    public function exportPeriodCertificateKS3(Request $request, mixed $certificate): JsonResponse
    {
        try {
            $certificate = $this->resolveCertificate($request, $certificate, ActReportAccessService::PERMISSION_EXPORT_EXCEL);

            return AdminResponse::success($this->exportService->exportCertificateKS3Excel($certificate));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_export_ks3_failed', [
                'certificate_id' => is_object($certificate) ? ($certificate->id ?? null) : $certificate,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    public function exportPeriodCertificateKS3Pdf(Request $request, mixed $certificate): JsonResponse
    {
        try {
            $certificate = $this->resolveCertificate($request, $certificate, ActReportAccessService::PERMISSION_EXPORT_PDF);

            return AdminResponse::success($this->exportService->exportCertificateKS3Pdf($certificate));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_export_ks3_pdf_failed', [
                'certificate_id' => is_object($certificate) ? ($certificate->id ?? null) : $certificate,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    public function uploadSignedCertificateFile(UploadSignedCertificateFileRequest $request, mixed $certificate): JsonResponse
    {
        try {
            $certificate = $this->resolveCertificate($request, $certificate, ActReportAccessService::PERMISSION_EDIT);
            $file = $request->file('file');
            if (! $file instanceof UploadedFile) {
                throw new BusinessLogicException(trans_message('act_reports.file_upload_failed'), 422);
            }
            $user = $request->user();
            if ($user === null) {
                throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
            }

            return AdminResponse::success(
                new ContractPeriodCertificateResource(
                    $this->fileService->uploadSignedCertificate(
                        $certificate,
                        $file,
                        $user,
                        $request->validated()['description'] ?? null
                    )
                ),
                trans_message('act_reports.certificate_signed_file_uploaded')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_signed_file_upload_failed', [
                'certificate_id' => is_object($certificate) ? ($certificate->id ?? null) : $certificate,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.file_upload_failed'), 500);
        }
    }

    public function downloadSignedCertificateFile(Request $request, mixed $certificate): JsonResponse|StreamedResponse
    {
        try {
            $certificate = $this->resolveCertificate($request, $certificate, ActReportAccessService::PERMISSION_VIEW);

            return $this->fileService->downloadSignedCertificate($certificate);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.certificate_signed_file_download_failed', [
                'certificate_id' => is_object($certificate) ? ($certificate->id ?? null) : $certificate,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.file_not_found'), 404);
        }
    }

    private function resolveCertificate(Request $request, mixed $certificate, string $permission): ContractPeriodCertificate
    {
        $organizationId = $this->accessService->currentOrganizationId($request);
        $this->accessService->authorize($request, $permission, $organizationId);
        $id = $certificate instanceof ContractPeriodCertificate ? (int) $certificate->id : (int) $certificate;
        $model = ContractPeriodCertificate::query()->find($id);
        if ($model === null || (int) $model->organization_id !== $organizationId) {
            throw new BusinessLogicException(trans_message('act_reports.certificate_not_found'), 404);
        }

        return $model;
    }

    public function preview(PreviewActRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_VIEW, $organizationId);

            return AdminResponse::success(
                $this->workflowService->preview($organizationId, $request->validated(), $request->user())
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
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
        return $this->storeFromWizardPayload($request);
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_VIEW, $organizationId);

            $filters = $request->only([
                'contract_id',
                'project_id',
                'contractor_id',
                'is_approved',
                'status',
                'date_from',
                'date_to',
                'search',
                'sort_by',
                'sort_direction',
            ]);

            $perPage = (int) $request->input('per_page', 15);
            $acts = $this->actReportService->getActsList($organizationId, $filters, $perPage);
            $summary = $this->actReportService->getActsSummary($organizationId, $filters);

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
                200,
                $summary,
                [
                    'first' => $acts->url(1),
                    'last' => $acts->url($acts->lastPage()),
                    'prev' => $acts->previousPageUrl(),
                    'next' => $acts->nextPageUrl(),
                ]
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.index_failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.load_failed'), 500);
        }
    }

    public function store(StoreActReportRequest $request): JsonResponse
    {
        return $this->storeFromWizardPayload($request);
    }

    public function getContracts(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorize(
                $request,
                ActReportAccessService::PERMISSION_CONTRACTS_VIEW,
                $organizationId
            );

            return AdminResponse::success($this->workflowService->getContracts($organizationId));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.contracts_failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.load_failed'), 500);
        }
    }

    public function bulkExportExcel(BulkExportActReportsRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorize(
                $request,
                ActReportAccessService::PERMISSION_BULK_EXPORT_EXCEL,
                $organizationId
            );

            return AdminResponse::success(
                $this->exportService->bulkExportExcel($organizationId, $request->validated()['act_ids'])
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.bulk_export_excel_failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    public function show(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $this->accessService->authorizeAct($request, $act);
            $this->accessService->authorize(
                $request,
                ActReportAccessService::PERMISSION_VIEW,
                $this->accessService->currentOrganizationId($request)
            );

            return AdminResponse::success(
                new ContractPerformanceActResource($this->workflowService->show($act))
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.show_failed', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.load_failed'), 500);
        }
    }

    public function update(UpdateActReportRequest $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorizeAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EDIT, $organizationId);

            return AdminResponse::success(
                new ContractPerformanceActResource($this->workflowService->update($act, $request->validated())),
                trans_message('act_reports.act_updated')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.update_failed', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.update_failed'), 500);
        }
    }

    public function submit(Request $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EDIT, $organizationId);

            return AdminResponse::success(
                new ContractPerformanceActResource($this->workflowService->submit($act, (int) $request->user()?->id)),
                trans_message('act_reports.act_submitted')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.submit_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.update_failed'), 500);
        }
    }

    public function approve(Request $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_APPROVE, $organizationId);

            return AdminResponse::success(
                new ContractPerformanceActResource($this->workflowService->approve($act, (int) $request->user()?->id)),
                trans_message('act_reports.act_approved')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.approve_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.update_failed'), 500);
        }
    }

    public function reject(RejectActReportRequest $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EDIT, $organizationId);

            return AdminResponse::success(
                new ContractPerformanceActResource(
                    $this->workflowService->reject($act, (int) $request->user()?->id, $request->validated()['reason'])
                ),
                trans_message('act_reports.act_rejected')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.reject_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.update_failed'), 500);
        }
    }

    public function annul(AnnulActReportRequest $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_APPROVE, $organizationId);
            $validated = $request->validated();

            return AdminResponse::success(
                new ContractPerformanceActResource($this->workflowService->annul(
                    $act,
                    (int) $request->user()?->id,
                    $validated['reason'],
                    $validated['idempotency_key'],
                )),
                trans_message('act_reports.act_annulled')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.annul_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.update_failed'), 500);
        }
    }

    public function exportPdf(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        return $this->export($request, $act, ActReportAccessService::PERMISSION_EXPORT_PDF, 'pdf');
    }

    public function exportExcel(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        return $this->export($request, $act, ActReportAccessService::PERMISSION_EXPORT_EXCEL, 'excel');
    }

    public function exportKS3(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        return $this->export($request, $act, ActReportAccessService::PERMISSION_EXPORT_EXCEL, 'ks3_excel');
    }

    public function exportKS3Pdf(Request $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EXPORT_PDF, $organizationId);

            return AdminResponse::success($this->exportService->exportKS3Pdf($act));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.export_ks3_pdf_error', $act, $e);

            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    public function uploadSignedFile(UploadSignedActFileRequest $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EDIT, $organizationId);

            return AdminResponse::success(
                new ContractPerformanceActResource(
                    $this->fileService->uploadSigned(
                        $act,
                        $request->file('file'),
                        $request->user(),
                        $request->validated()['description'] ?? null
                    )
                ),
                trans_message('act_reports.signed_file_uploaded')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.signed_file_upload_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.file_upload_failed'), 500);
        }
    }

    public function uploadFile(UploadActReportFileRequest $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EDIT, $organizationId);

            $file = $this->fileService->upload(
                $act,
                $request->file('file'),
                $request->user(),
                'act_document',
                $request->validated()['description'] ?? null
            );

            return AdminResponse::success(
                $this->fileService->format($file),
                trans_message('act_reports.file_uploaded'),
                201
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.file_upload_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.file_upload_failed'), 500);
        }
    }

    public function getFiles(Request $request, mixed $act): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_VIEW, $organizationId);

            return AdminResponse::success($this->fileService->list($act));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActActionFailure('act_reports.files_failed', $act, $e);

            return AdminResponse::error(trans_message('act_reports.load_failed'), 500);
        }
    }

    public function downloadFile(Request $request, mixed $act, mixed $file): JsonResponse|StreamedResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_DOWNLOAD_PDF, $organizationId);

            return $this->fileService->download($act, $file);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActFileFailure('act_reports.file_download_failed', $act, $file, $e);

            return AdminResponse::error(trans_message('act_reports.file_not_found'), 404);
        }
    }

    public function deleteFile(Request $request, mixed $act, mixed $file): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_EDIT, $organizationId);
            $this->fileService->delete($act, $file);

            return AdminResponse::success(null, trans_message('act_reports.file_deleted'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActFileFailure('act_reports.file_delete_failed', $act, $file, $e);

            return AdminResponse::error(trans_message('act_reports.file_not_found'), 404);
        }
    }

    public function downloadPdf(Request $request, mixed $act, mixed $file): JsonResponse|StreamedResponse
    {
        return $this->downloadFile($request, $act, $file);
    }

    public function copyToPersonalStorage(Request $request, mixed $act, mixed $file): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $act = $this->accessService->resolveAccessibleAct($request, $act);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_DOWNLOAD_PDF, $organizationId);

            return AdminResponse::success(
                $this->fileService->copyToPersonalStorage($act, $file, $request->user()),
                trans_message('act_reports.file_copied'),
                201
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            $this->logActFileFailure('act_reports.file_copy_failed', $act, $file, $e);

            return AdminResponse::error(trans_message('act_reports.file_upload_failed'), 500);
        }
    }

    private function storeFromWizardPayload(StoreActFromWizardRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorize($request, ActReportAccessService::PERMISSION_CREATE, $organizationId);

            $data = $request->validated();
            $manualLines = $data['manual_lines'] ?? [];
            $canManageManualLines = $manualLines === []
                || (bool) $request->user()?->can(
                    ActReportAccessService::PERMISSION_MANAGE_WORKS,
                    ['organization_id' => $organizationId]
                );

            return AdminResponse::success(
                new ContractPerformanceActResource(
                    $this->workflowService->createFromWizard(
                        $organizationId,
                        $data,
                        $request->user(),
                        $canManageManualLines
                    )
                ),
                trans_message('act_reports.act_created'),
                201
            );
        } catch (ActingQuantityConflictException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode(), null, $e->payload());
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error('act_reports.create_from_wizard_failed', [
                'contract_id' => $request->input('contract_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.create_failed'), 500);
        }
    }

    private function export(
        Request $request,
        ContractPerformanceAct $act,
        string $permission,
        string $format
    ): JsonResponse {
        try {
            $organizationId = $this->accessService->currentOrganizationId($request);
            $this->accessService->authorizeAct($request, $act);
            $this->accessService->authorize($request, $permission, $organizationId);

            $payload = match ($format) {
                'pdf' => $this->exportService->exportPdf($act),
                'excel' => $this->exportService->exportExcel($act),
                'ks3_excel' => $this->exportService->exportKS3Excel($act),
            };

            return AdminResponse::success($payload);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
        } catch (Throwable $e) {
            Log::error("act_reports.export_{$format}_error", [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('act_reports.export_failed'), 500);
        }
    }

    private function logActActionFailure(string $message, mixed $act, Throwable $e): void
    {
        Log::error($message, [
            'act_id' => $act instanceof ContractPerformanceAct ? $act->id : $act,
            'error' => $e->getMessage(),
        ]);
    }

    private function logActFileFailure(string $message, mixed $act, mixed $file, Throwable $e): void
    {
        Log::error($message, [
            'act_id' => $act instanceof ContractPerformanceAct ? $act->id : $act,
            'file_id' => $file,
            'error' => $e->getMessage(),
        ]);
    }
}
