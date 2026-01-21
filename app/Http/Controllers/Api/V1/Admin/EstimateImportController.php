<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Http\Requests\Admin\Estimate\UploadEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\DetectEstimateTypeRequest;
use App\Http\Requests\Admin\Estimate\DetectEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\MapEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\ExecuteEstimateImportRequest;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportPreviewResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportResultResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportHistoryResource;
use App\Services\Organization\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateImportController extends Controller
{
    public function __construct(
        private EstimateImportService $importService
    ) {}

    public function upload(UploadEstimateImportRequest $request): JsonResponse
    {
        $user = $request->user();
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        
        // Get file FIRST before any other operations
        $file = $request->file('file');
        
        if (!$file) {
            Log::error('[EstimateImport] No file in request');
            return AdminResponse::error(
                trans_message('estimate.import_no_file'),
                Response::HTTP_BAD_REQUEST
            );
        }
        
        // Get file info IMMEDIATELY while file is still available
        try {
            $fileName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $filePath = $file->getRealPath();
            
            Log::info('[EstimateImport] File info captured', [
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'file_exists' => file_exists($filePath),
                'file_path' => $filePath,
                'user_id' => $user?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[EstimateImport] Failed to get file info', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
            ]);
            return AdminResponse::error(
                trans_message('estimate.import_file_process_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        
        if (!$organization) {
            Log::error('[EstimateImport] Organization not found', [
                'user_id' => $user?->id,
            ]);
            return AdminResponse::error(
                trans_message('estimate.import_organization_not_found'),
                Response::HTTP_BAD_REQUEST
            );
        }
        
        try {
            Log::info('[EstimateImport] Starting file upload', [
                'file_name' => $fileName,
                'file_size' => $fileSize,
            ]);
            
            $fileId = $this->importService->uploadFile($file, $user->id, $organization->id);
            
            Log::info('[EstimateImport] Upload successful', ['file_id' => $fileId]);
            
            return AdminResponse::success([
                'file_id' => $fileId,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'expires_at' => now()->addHours(24)->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(
                trans_message('estimate.import_upload_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Определить тип сметы по содержимому файла
     */
    public function detectType(DetectEstimateTypeRequest $request): JsonResponse
    {
        $fileId = $request->input('file_id');
        
        try {
            Log::info('[EstimateImport] detectType called', [
                'file_id' => $fileId,
                'user_id' => $request->user()?->id,
            ]);
            
            $detectionDTO = $this->importService->detectEstimateType($fileId);
            
            return AdminResponse::success([
                'detected_type' => $detectionDTO->detectedType,
                'type_description' => $detectionDTO->getTypeDescription(),
                'confidence' => $detectionDTO->confidence,
                'is_high_confidence' => $detectionDTO->isHighConfidence(),
                'indicators' => $detectionDTO->indicators,
                'candidates' => $detectionDTO->candidates,
            ]);
        } catch (\Exception $e) {
            Log::error('[EstimateImport] detectType failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error(
                trans_message('estimate.import_detect_type_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function detect(DetectEstimateImportRequest $request): JsonResponse
    {
        $fileId = $request->input('file_id');
        $suggestedHeaderRow = $request->input('suggested_header_row');
        
        Log::info('[EstimateImport] Detect started', [
            'file_id' => $fileId,
            'suggested_header_row' => $suggestedHeaderRow,
        ]);
        
        try {
            $detection = $this->importService->detectFormat($fileId, $suggestedHeaderRow);
            
            Log::info('[EstimateImport] Detect completed successfully', [
                'header_row' => $detection['header_row'] ?? null,
                'candidates_count' => count($detection['header_candidates'] ?? []),
            ]);
            
            return AdminResponse::success([
                'format' => $detection['format'],
                'detected_columns' => $detection['detected_columns'],
                'raw_headers' => $detection['raw_headers'],
                'header_row' => $detection['header_row'],
                'header_candidates' => $detection['header_candidates'],
                'sample_rows' => $detection['sample_rows'],
            ]);
            
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Detect failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(
                trans_message('estimate.import_detect_format_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    public function map(MapEstimateImportRequest $request): JsonResponse
    {
        $fileIdRaw = $request->input('file_id');
        $fileId = is_array($fileIdRaw) ? (string) ($fileIdRaw[0] ?? '') : (string) $fileIdRaw;
        $columnMapping = $request->input('column_mapping');
        
        try {
            $preview = $this->importService->preview($fileId, $columnMapping);
            
            return AdminResponse::success([
                'preview' => new EstimateImportPreviewResource($preview),
                'validation' => [
                    'errors' => [],
                    'warnings' => [],
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Preview failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(
                trans_message('estimate.import_preview_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    public function match(Request $request): JsonResponse
    {
        Log::info('[EstimateImport] Match request received', [
            'all_input' => $request->all(),
            'file_id_raw' => $request->input('file_id'),
            'file_id_type' => gettype($request->input('file_id')),
        ]);
        
        $request->validate([
            'file_id' => ['required', 'string'],
        ]);
        
        $fileIdRaw = $request->input('file_id');
        $fileId = is_array($fileIdRaw) ? (string) ($fileIdRaw[0] ?? '') : (string) $fileIdRaw;
        
        Log::info('[EstimateImport] Parsed file_id', [
            'file_id' => $fileId,
            'length' => strlen($fileId),
        ]);
        
        if (empty($fileId)) {
            Log::warning('[EstimateImport] Empty file_id received in match request', [
                'request_data' => $request->all(),
            ]);
            
            return AdminResponse::error(
                trans_message('estimate.import_file_id_empty'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        
        try {
            $matchResult = $this->importService->analyzeMatches($fileId, $organization->id);
            
            return AdminResponse::success($matchResult);
            
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('estimate.import_match_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    public function execute(ExecuteEstimateImportRequest $request): JsonResponse
    {
        $fileIdRaw = $request->input('file_id');
        $fileId = is_array($fileIdRaw) ? (string) ($fileIdRaw[0] ?? '') : (string) $fileIdRaw;
        $matchingConfig = $request->input('matching_config');
        $estimateSettings = $request->input('estimate_settings');
        
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        $estimateSettings['organization_id'] = $organization->id;
        
        $projectId = $request->route('project');
        if ($projectId && !isset($estimateSettings['project_id'])) {
            $estimateSettings['project_id'] = $projectId;
        }
        
        if (!isset($estimateSettings['project_id'])) {
            return AdminResponse::error(
                trans_message('estimate.import_project_required'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        
        try {
            $result = $this->importService->execute($fileId, $matchingConfig, $estimateSettings);
            
            return AdminResponse::success($result);
            
        } catch (\Exception $e) {
            return AdminResponse::error(
                trans_message('estimate.import_execute_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    public function status(Request $request, string $project, string $jobId): JsonResponse
    {
        try {
            Log::info('[EstimateImport] 🎯 Status endpoint called', [
                'project' => $project,
                'job_id' => $jobId,
                'url' => $request->fullUrl(),
            ]);
            
            $status = $this->importService->getImportStatus($jobId);
            
            return AdminResponse::success($status);
            
        } catch (\Exception $e) {
            Log::error('[EstimateImport] ❌ Status endpoint error', [
                'project' => $project,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error(
                trans_message('estimate.import_status_not_found'),
                Response::HTTP_NOT_FOUND
            );
        }
    }

    public function history(Request $request): JsonResponse
    {
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        $limit = $request->input('limit', 50);
        
        $history = $this->importService->getImportHistory($organization->id, $limit);
        
        return AdminResponse::success(
            EstimateImportHistoryResource::collection($history)
        );
    }
}

