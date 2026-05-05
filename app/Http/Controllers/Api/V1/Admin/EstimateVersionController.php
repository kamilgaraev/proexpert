<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionComparisonService;
use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionRestoreService;
use App\BusinessModules\Features\BudgetEstimates\Services\WhatIfSimulatorService;
use App\BusinessModules\Features\BudgetEstimates\Services\AutoSchedulingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\MemoryLayerService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateVersionController extends Controller
{
    public function __construct(
        protected EstimateVersioningService $versioningService,
        protected EstimateVersionComparisonService $versionComparisonService,
        protected EstimateVersionRestoreService $versionRestoreService,
        protected WhatIfSimulatorService $whatIfService,
        protected AutoSchedulingService $schedulerService,
        protected MemoryLayerService $memoryLayer
    ) {}

    public function index(int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        $this->authorize('view', $estimate);
        
        $history = $this->versioningService->listVersions($estimate);
        
        return AdminResponse::success($history);
    }

    public function store(Request $request, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        $this->authorizeVersionCreation($estimate);
        
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'comment' => 'nullable|string|max:1000',
        ]);
        
        $version = $this->versioningService->createSnapshot(
            estimate: $estimate,
            actorId: (int) $request->user()->id,
            label: $validated['label'],
            comment: $validated['comment'] ?? null
        );
        
        return AdminResponse::success(
            $this->versioningService->resourcePayload($version),
            trans_message('estimate.version_created'),
            Response::HTTP_CREATED
        );
    }

    public function compare(Request $request, int $estimateId): JsonResponse
    {
        $validated = $request->validate([
            'version_a_id' => ['required', 'integer'],
            'version_b_id' => ['required', 'integer'],
        ]);

        try {
            $estimate = $this->findEstimateOrFail($estimateId);
            $this->authorize('view', $estimate);
            $this->authorize('compareVersions', Estimate::class);

            $versionA = $this->findVersionForEstimate($estimate, (int) $validated['version_a_id']);
            $versionB = $this->findVersionForEstimate($estimate, (int) $validated['version_b_id']);

            $comparison = $this->versionComparisonService->compare($versionA, $versionB);
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('estimate.version_not_found'), Response::HTTP_NOT_FOUND);
        }
        
        return AdminResponse::success($comparison);
    }

    public function rollback(Request $request, int $estimateId, int $versionId): JsonResponse
    {
        try {
            $estimate = $this->findEstimateOrFail($estimateId);
            $version = $this->findVersionForEstimate($estimate, $versionId);
            $this->authorize('rollbackVersion', $version->estimate);
            $restoredEstimate = $this->versionRestoreService->restore(
                estimate: $version->estimate,
                version: $version,
                actorId: (int) $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('estimate.version_not_found'), Response::HTTP_NOT_FOUND);
        }

        return AdminResponse::success(
            new EstimateResource($restoredEstimate),
            trans_message('estimate.version_rollback'),
            Response::HTTP_CREATED
        );
    }


    public function whatIf(Request $request, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        $this->authorize('view', $estimate);
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

    public function schedule(Request $request, int $estimateId): JsonResponse
    {
        $estimate = $this->findEstimateOrFail($estimateId);
        $this->authorize('view', $estimate);
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

    /**
     * Найти смету с проверкой организации
     */
    private function findEstimateOrFail(int $estimateId): Estimate
    {
        $organizationId = request()->attributes->get('current_organization_id');
        
        return Estimate::where('id', $estimateId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
    }

    private function findVersionForEstimate(Estimate $estimate, int $versionId): EstimateVersion
    {
        return EstimateVersion::query()
            ->whereKey($versionId)
            ->where('estimate_id', $estimate->id)
            ->where('organization_id', $estimate->organization_id)
            ->with('estimate')
            ->firstOrFail();
    }

    private function authorizeVersionCreation(Estimate $estimate): void
    {
        $policy = Gate::getPolicyFor($estimate);

        if ($policy !== null && method_exists($policy, 'createVersion')) {
            $this->authorize('createVersion', $estimate);
            return;
        }

        $this->authorize('update', $estimate);
    }
}
