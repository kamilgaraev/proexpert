<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Http\Requests\Admin\Estimate\UploadEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\DetectEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\MapEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\ExecuteEstimateImportRequest;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportPreviewResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportResultResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportHistoryResource;
use App\Services\Organization\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
            return response()->json([
                'success' => false,
                'message' => 'No file uploaded',
            ], 400);
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to process uploaded file: ' . $e->getMessage(),
            ], 500);
        }
        
        if (!$organization) {
            Log::error('[EstimateImport] Organization not found', [
                'user_id' => $user?->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Organization context not found',
            ], 400);
        }
        
        try {
            Log::info('[EstimateImport] Starting file upload', [
                'file_name' => $fileName,
                'file_size' => $fileSize,
            ]);
            
            $fileId = $this->importService->uploadFile($file, $user->id, $organization->id);
            
            Log::info('[EstimateImport] Upload successful', ['file_id' => $fileId]);
            
            return response()->json([
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
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload file: ' . $e->getMessage(),
            ], 500);
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
            
            return response()->json([
                'success' => true,
                'format' => $detection['format'],
                'detected_columns' => $detection['detected_columns'],
                'raw_headers' => $detection['raw_headers'],
                'header_row' => $detection['header_row'],
                'header_candidates' => $detection['header_candidates'], // Топ кандидатов для UI
                'sample_rows' => $detection['sample_rows'],
            ]);
            
        } catch (\Exception $e) {
            Log::error('[EstimateImport] Detect failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Не удалось определить структуру файла: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function map(MapEstimateImportRequest $request): JsonResponse
    {
        $fileIdRaw = $request->input('file_id');
        $fileId = is_array($fileIdRaw) ? (string) ($fileIdRaw[0] ?? '') : (string) $fileIdRaw;
        $columnMapping = $request->input('column_mapping');
        
        try {
            $preview = $this->importService->preview($fileId, $columnMapping);
            
            return response()->json([
                'preview' => new EstimateImportPreviewResource($preview),
                'validation' => [
                    'errors' => [],
                    'warnings' => [],
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ошибка при создании preview',
                'message' => $e->getMessage(),
            ], 422);
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
            
            return response()->json([
                'error' => 'Некорректный запрос',
                'message' => 'file_id не может быть пустым. Убедитесь, что вы передаете file_id полученный от /upload.',
                'hint' => 'Проверьте документацию: @docs/FRONTEND_FIX_MATCH_ENDPOINT.md',
            ], 422);
        }
        
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        
        try {
            $matchResult = $this->importService->analyzeMatches($fileId, $organization->id);
            
            return response()->json($matchResult);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ошибка при сопоставлении работ',
                'message' => $e->getMessage(),
            ], 422);
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
            return response()->json([
                'error' => 'Смета должна быть импортирована в контексте проекта',
                'message' => 'Параметр project_id обязателен',
            ], 422);
        }
        
        try {
            $result = $this->importService->execute($fileId, $matchingConfig, $estimateSettings);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Ошибка при выполнении импорта',
                'message' => $e->getMessage(),
            ], 422);
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
            
            return response()->json($status);
            
        } catch (\Exception $e) {
            Log::error('[EstimateImport] ❌ Status endpoint error', [
                'project' => $project,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'error' => 'Статус импорта не найден',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        $limit = $request->input('limit', 50);
        
        $history = $this->importService->getImportHistory($organization->id, $limit);
        
        return response()->json([
            'data' => EstimateImportHistoryResource::collection($history),
        ]);
    }
}

