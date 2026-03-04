<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractPerformanceActService;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Services\Storage\FileService;
use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Exception;

class ContractPerformanceActController extends Controller
{
    protected ContractPerformanceActService $actService;
    protected \App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService $officialExportService;
    protected FileService $fileService;

    public function __construct(
        ContractPerformanceActService $actService, 
        \App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService $officialExportService,
        FileService $fileService
    ) {
        $this->actService = $actService;
        $this->officialExportService = $officialExportService;
        $this->fileService = $fileService;
    }
    
    /**
     * Экспорт акта в PDF (KS-2)
     */
    public function exportPdf(Request $request, int $project, int $contract, int $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            $projectId = $project;

            $actModel = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$actModel) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $actModel)) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $path = $this->officialExportService->exportKS2ToPdf($actModel, $actModel->contract);
            $url = $this->officialExportService->getFileService()->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);

        } catch (Exception $e) {
            Log::error('Ошибка экспорта PDF акта KS-2', [
                'contract_id' => $contract,
                'act_id' => $act,
                'error' => $e->getMessage()
            ]);
            
            return AdminResponse::error(trans_message('contract.act_export_error'), 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Экспорт акта в Excel (KS-2)
     */
    public function exportExcel(Request $request, int $project, int $contract, int $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;
            $projectId = $project;

            $actModel = $this->actService->getActById($act, $contract, $organizationId, $projectId);
            if (!$actModel) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $actModel)) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $path = $this->officialExportService->exportKS2ToExcel($actModel, $actModel->contract);
            $url = $this->officialExportService->getFileService()->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);

        } catch (Exception $e) {
            Log::error('Ошибка экспорта Excel акта KS-2', [
                'contract_id' => $contract,
                'act_id' => $act,
                'error' => $e->getMessage()
            ]);
            
            return AdminResponse::error(trans_message('contract.act_export_error'), 500, config('app.debug') ? $e->getMessage() : null);
        }
    }

    /**
     * Экспорт справки КС-3 в Excel
     */
    public function exportKS3(Request $request, int $project, int $contract, int $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            $actModel = $this->actService->getActById($act, $contract, $organizationId, $project);
            if (!$actModel) {
                return AdminResponse::error(trans_message('contract.act_not_found'), Response::HTTP_NOT_FOUND);
            }

            $path = $this->officialExportService->exportKS3ToExcel($actModel, $actModel->contract);
            $url = $this->officialExportService->getFileService()->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url]);
        } catch (Exception $e) {
            return AdminResponse::error('Ошибка экспорта КС-3', 500, $e->getMessage());
        }
    }

    /**
     * Получить список файлов акта
     */
    public function getFiles(Request $request, int $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $request->attributes->get('current_organization');
            $organizationId = $organization?->id ?? $user?->current_organization_id;

            if (!$organizationId) {
                return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
            }

            // Получаем акт с контрактом напрямую
            $performanceAct = \App\Models\ContractPerformanceAct::with('contract')->find($act);
            
            if (!$performanceAct) {
                return AdminResponse::error(trans_message('contract.act_not_found'), 404);
            }

            // Проверяем доступ к организации
            if ($performanceAct->contract->organization_id !== $organizationId) {
                return AdminResponse::error(trans_message('contract.access_denied'), 403);
            }

            // Загружаем файлы с информацией о пользователях
            $performanceAct->load(['files.user']);

            $org = Organization::find($organizationId);
            $disk = $this->fileService->disk($org);

            $files = $performanceAct->files->map(function ($file) use ($disk) {
                $downloadUrl = null;
                try {
                    if ($disk->exists($file->path)) {
                        $downloadUrl = $disk->temporaryUrl($file->path, now()->addHours(1));
                    }
                } catch (Exception $e) {
                    Log::warning('Не удалось создать временный URL для файла', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage()
                    ]);
                }

                return [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                    'category' => $file->category,
                    'uploaded_by' => $file->user ? $file->user->name : 'Не указан',
                    'uploaded_at' => $file->created_at->toIso8601String(),
                    'description' => $file->additional_info['description'] ?? null,
                    'download_url' => $downloadUrl
                ];
            });

            return AdminResponse::success($files);

        } catch (Exception $e) {
            Log::error('Ошибка получения файлов акта', [
                'act_id' => $act,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return AdminResponse::error(trans_message('contract.act_files_error'), 500, $e->getMessage());
        }
    }
} 