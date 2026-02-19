<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersionService;
use App\BusinessModules\Features\BudgetEstimates\Services\StructuralDiffService;
use App\BusinessModules\Features\BudgetEstimates\Services\WhatIfSimulatorService;
use App\BusinessModules\Features\BudgetEstimates\Services\AutoSchedulingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\MemoryLayerService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateVersionController extends Controller
{
    public function __construct(
        protected EstimateVersionService $versionService,
        protected StructuralDiffService $diffService,
        protected WhatIfSimulatorService $whatIfService,
        protected AutoSchedulingService $schedulerService,
        protected MemoryLayerService $memoryLayer
    ) {}

    public function index(Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);
        
        $history = $this->versionService->getVersionHistory($estimate);
        
        return AdminResponse::success($history);
    }

    public function store(Request $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);
        
        $validated = $request->validate([
            'description' => 'nullable|string|max:1000',
        ]);
        
        $newVersion = $this->versionService->createVersion(
            $estimate,
            $validated['description'] ?? null
        );
        
        return AdminResponse::success(
            new EstimateResource($newVersion),
            trans_message('estimate.version_created'),
            Response::HTTP_CREATED
        );
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version1_id' => 'required|exists:estimates,id',
            'version2_id' => 'required|exists:estimates,id',
        ]);
        
        $version1 = Estimate::findOrFail($validated['version1_id']);
        $version2 = Estimate::findOrFail($validated['version2_id']);
        
        $this->authorize('view', $version1);
        $this->authorize('view', $version2);
        
        $comparison = $this->versionService->compareVersions($version1, $version2);
        
        return AdminResponse::success($comparison);
    }

    public function rollback(Estimate $version): JsonResponse
    {
        $this->authorize('update', $version);
        
        $newVersion = $this->versionService->rollback($version);
        
        return AdminResponse::success(
            new EstimateResource($newVersion),
            trans_message('estimate.version_rollback'),
            Response::HTTP_CREATED
        );
    }

    public function snapshotDiff(Request $request, Estimate $estimate): JsonResponse
    {
        $request->validate([
            'version_a_id' => ['required', 'integer', 'exists:estimate_versions,id'],
            'version_b_id' => ['required', 'integer', 'exists:estimate_versions,id'],
        ]);

        try {
            $diff = $this->diffService->diff(
                (int)$request->input('version_a_id'),
                (int)$request->input('version_b_id')
            );
            return AdminResponse::success($diff);
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('[EstimateVersion] SnapshotDiff failed', ['error' => $e->getMessage()]);
            return AdminResponse::error('Ошибка сравнения версий', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function whatIf(Request $request, mixed $project, Estimate $estimate): JsonResponse
    {
        $request->validate([
            'materials_index' => ['nullable', 'numeric', 'min:0'],
            'machinery_index' => ['nullable', 'numeric', 'min:0'],
            'labor_index'     => ['nullable', 'numeric', 'min:0'],
            'global_index'    => ['nullable', 'numeric', 'min:0'],
            'vat_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'overhead_rate'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'profit_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $result = $this->whatIfService->simulate($estimate->id, $request->only([
                'materials_index', 'machinery_index', 'labor_index', 'global_index',
                'vat_rate', 'overhead_rate', 'profit_rate',
            ]));
            return AdminResponse::success($result);
        } catch (\Throwable $e) {
            Log::error('[EstimateVersion] WhatIf failed', ['error' => $e->getMessage()]);
            return AdminResponse::error('Ошибка симуляции', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function schedule(Request $request, mixed $project, Estimate $estimate): JsonResponse
    {
        $request->validate([
            'start_date'        => ['nullable', 'date'],
            'workdays_per_week' => ['nullable', 'integer', 'min:1', 'max:7'],
        ]);

        try {
            $schedule = $this->schedulerService->generateSchedule($estimate->id, $request->only([
                'start_date', 'workdays_per_week',
            ]));
            return AdminResponse::success($schedule);
        } catch (\Throwable $e) {
            Log::error('[EstimateVersion] Schedule generation failed', ['error' => $e->getMessage()]);
            return AdminResponse::error('Ошибка генерации расписания', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function memoryList(Request $request): JsonResponse
    {
        $organizationId = Auth::user()?->currentOrganization?->id;

        if (!$organizationId) {
            return AdminResponse::error('Организация не найдена', Response::HTTP_BAD_REQUEST);
        }

        $list = $this->memoryLayer->listForOrganization($organizationId);
        return AdminResponse::success(['memories' => $list, 'total' => count($list)]);
    }

    public function memoryFeedback(Request $request): JsonResponse
    {
        $request->validate([
            'memory_id'   => ['required', 'integer'],
            'was_correct' => ['required', 'boolean'],
        ]);

        $this->memoryLayer->feedback(
            (int)$request->input('memory_id'),
            (bool)$request->input('was_correct')
        );

        return AdminResponse::success(['message' => 'Обратная связь принята']);
    }
}

