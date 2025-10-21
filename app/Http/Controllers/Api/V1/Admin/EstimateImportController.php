<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Http\Requests\Admin\Estimate\UploadEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\MapEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\ExecuteEstimateImportRequest;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportPreviewResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportResultResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportHistoryResource;
use App\Services\Organization\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EstimateImportController extends Controller
{
    public function __construct(
        private EstimateImportService $importService
    ) {}

    public function upload(UploadEstimateImportRequest $request): JsonResponse
    {
        $user = $request->user();
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        
        if (!$organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context not found',
            ], 400);
        }
        
        $file = $request->file('file');
        
        try {
            $fileId = $this->importService->uploadFile($file, $user->id, $organization->id);
            
            return response()->json([
                'file_id' => $fileId,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'expires_at' => now()->addHours(24)->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload file: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function detect(Request $request): JsonResponse
    {
        $request->validate([
            'file_id' => ['required', 'string'],
        ]);
        
        $fileId = $request->input('file_id');
        
        try {
            $detection = $this->importService->detectFormat($fileId);
            
            return response()->json([
                'success' => true,
                ...$detection,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось определить структуру файла: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function map(MapEstimateImportRequest $request): JsonResponse
    {
        $fileId = $request->input('file_id');
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
        $request->validate([
            'file_id' => ['required', 'string'],
        ]);
        
        $fileId = $request->input('file_id');
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
        $fileId = $request->input('file_id');
        $matchingConfig = $request->input('matching_config');
        $estimateSettings = $request->input('estimate_settings');
        
        $organization = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        $estimateSettings['organization_id'] = $organization->id;
        
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

    public function status(Request $request, string $jobId): JsonResponse
    {
        try {
            $status = $this->importService->getImportStatus($jobId);
            
            return response()->json($status);
            
        } catch (\Exception $e) {
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

