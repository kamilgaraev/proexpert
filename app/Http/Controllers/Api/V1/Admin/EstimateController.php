<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateStructureSnapshotStorage;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateStatusWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Estimate\CreateEstimateRequest;
use App\Http\Requests\Admin\Estimate\UpdateEstimateRequest;
use App\Http\Requests\Admin\Estimate\UpdateEstimateStatusRequest;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateListResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Repositories\EstimateRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

class EstimateController extends Controller
{
    public function __construct(
        protected EstimateService $estimateService,
        protected EstimateCalculationService $calculationService,
        protected EstimateRepository $repository,
        protected EstimateCoverageService $coverageService,
        private readonly EstimateStructureSnapshotStorage $structureSnapshotStorage,
        private readonly EstimateStatusWorkflowService $statusWorkflow
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;

        $filters = [
            'status' => $request->input('status'),
            'type' => $request->input('type'),
            'project_id' => $request->route('project') ?? $request->input('project_id'),
            'contract_id' => $request->input('contract_id'),
            'search' => $request->input('search'),
        ];

        $estimates = $this->repository->getByOrganization(
            $organizationId,
            array_filter($filters),
            $request->input('per_page', 15)
        );

        return AdminResponse::paginated(
            EstimateListResource::collection($estimates),
            [
                'current_page' => $estimates->currentPage(),
                'per_page' => $estimates->perPage(),
                'total' => $estimates->total(),
                'last_page' => $estimates->lastPage(),
            ]
        );
    }

    public function store(CreateEstimateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $request->user()->current_organization_id;

        $projectId = $request->route('project');
        if (! $projectId) {
            return AdminResponse::error(trans_message('estimate.project_context_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data['project_id'] = $projectId;

        $estimate = $this->estimateService->create($data);

        return AdminResponse::success(
            new EstimateResource($estimate),
            trans_message('estimate.created'),
            Response::HTTP_CREATED
        );
    }

    public function show(Request $request, $project, int $estimate): mixed
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (! $organizationId) {
            $organizationId = $request->user()?->current_organization_id;
        }

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', (int) $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('view', $estimateModel);

        // Грузим только плоские связи для меты. Не грузим sections.items!
        $estimateModel->load(['project', 'approvedBy']);

        // Если снапшот есть - стримим его с огромной экономией RAM
        if ($this->structureSnapshotStorage->exists($estimateModel->structure_cache_path)) {
            return $this->streamEstimateWithStructureSnapshot($estimateModel);
        }

        // Снапшот отсутствует — запускаем генерацию (синхронно или в фоне)
        // Для больших смет генерация через Job работает сверхбыстро (Raw Builder)
        try {
            \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::dispatchSync($estimateModel->id);
            $estimateModel->refresh();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[EstimateController] Snapshot generation failed, falling back to metadata-only', [
                'estimate_id' => $estimateModel->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Проверяем снова
        if ($this->structureSnapshotStorage->exists($estimateModel->structure_cache_path)) {
            return $this->streamEstimateWithStructureSnapshot($estimateModel);
        }

        // Финальный фолбэк (очень редкий случай, если джоба упала).
        // ТОЛЬКО ЗДЕСЬ мы грузим гигантское дерево в память Eloquent.
        $estimateModel->load([
            'sections.items.measurementUnit',
            'sections.items.contractLinks.contract.contractor',
            'items.contractLinks.contract.contractor',
        ]);

        return AdminResponse::success(new EstimateResource($estimateModel));
    }

    public function update(UpdateEstimateRequest $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('update', $estimateModel);

        $estimate = $this->estimateService->update($estimateModel, $request->validated());

        return AdminResponse::success(
            new EstimateResource($estimate),
            trans_message('estimate.updated')
        );
    }

    public function destroy(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('delete', $estimateModel);

        try {
            $this->estimateService->delete($estimateModel);

            return AdminResponse::success(null, trans_message('estimate.deleted'));
        } catch (\Exception $e) {
            return AdminResponse::error(trans_message('estimate.delete_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function duplicate(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('create', Estimate::class);

        $newEstimate = $this->estimateService->duplicate(
            $estimateModel,
            $request->input('number'),
            $request->input('name')
        );

        return AdminResponse::success(
            new EstimateResource($newEstimate),
            trans_message('estimate.duplicated'),
            Response::HTTP_CREATED
        );
    }

    public function recalculate(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('update', $estimateModel);

        $totals = $this->calculationService->recalculateAll($estimateModel);

        return AdminResponse::success($totals, trans_message('estimate.recalculated'));
    }

    public function dashboard(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('view', $estimateModel);

        $statistics = $estimateModel->statistics ?? [];
        $itemsCount = $statistics['items_count'] ?? $estimateModel->items()->count();
        $sectionsCount = $statistics['sections_count'] ?? $estimateModel->sections()->count();

        $structure = $this->calculationService->getEstimateStructure($estimateModel);

        $versions = $this->repository->getVersions($estimateModel);

        return AdminResponse::success([
            'estimate' => new EstimateResource($estimateModel),
            'statistics' => [
                'items_count' => $itemsCount,
                'sections_count' => $sectionsCount,
                'total_amount' => $estimateModel->total_amount,
                'total_amount_with_vat' => $estimateModel->total_amount_with_vat,
            ],
            'cost_structure' => $structure,
            'versions' => $versions->map(fn ($v) => [
                'id' => $v->id,
                'version' => $v->version,
                'created_at' => $v->created_at,
            ]),
            'related' => [
                'project' => $estimateModel->project,
                'coverage' => $this->coverageService->getCoverageForEstimate($estimateModel),
            ],
        ]);
    }

    public function structure(Request $request, $project, int $estimate): mixed
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $this->authorize('view', $estimateModel);

        if ($this->structureSnapshotStorage->exists($estimateModel->structure_cache_path)) {
            $snapshotPath = (string) $estimateModel->structure_cache_path;

            return response()->stream(function () use ($snapshotPath) {
                echo '{"success":true,"message":null,"data":';
                $stream = $this->structureSnapshotStorage->readStream($snapshotPath);
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                }
                fclose($stream);
                echo '}';
            }, 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        // Оптимизированная загрузка структуры (fallback)
        $sections = $estimateModel->sections()
            ->whereNull('parent_section_id')
            ->with([
                'items.workType',
                'items.measurementUnit',
                'items.contractLinks.contract.contractor',
                'items.resources',
                'items.works',
                'items.totals',
                'items.childItems' => function ($q) {
                    $q->with(['workType', 'measurementUnit', 'contractLinks.contract.contractor', 'resources', 'works', 'totals', 'childItems']);
                },
                'children' => function ($query) {
                    $query->with([
                        'items.workType',
                        'items.measurementUnit',
                        'items.contractLinks.contract.contractor',
                        'items.resources',
                        'items.works',
                        'items.totals',
                        'items.childItems' => function ($q) {
                            $q->with(['workType', 'measurementUnit', 'resources', 'works', 'totals', 'childItems']);
                        },
                        'children' => function ($q) {
                            $q->with([
                                'items.workType',
                                'items.measurementUnit',
                                'items.contractLinks.contract.contractor',
                                'items.resources',
                                'items.works',
                                'items.totals',
                                'items.childItems' => function ($q2) {
                                    $q2->with(['workType', 'measurementUnit', 'contractLinks.contract.contractor', 'resources', 'works', 'totals', 'childItems']);
                                },
                            ])->orderBy('sort_order');
                        },
                    ])->orderBy('sort_order');
                },
            ])
            ->orderBy('sort_order')
            ->get();

        return AdminResponse::success($sections);
    }

    private function streamEstimateWithStructureSnapshot(Estimate $estimate): StreamedResponse
    {
        $meta = (new EstimateResource($estimate))->resolve();
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $snapshotPath = (string) $estimate->structure_cache_path;

        return response()->stream(function () use ($metaJson, $snapshotPath) {
            echo '{"success":true,"message":null,"data":';
            echo $metaJson;
            echo ',"tree":';
            $stream = $this->structureSnapshotStorage->readStream($snapshotPath);
            while (! feof($stream)) {
                echo fread($stream, 8192);
            }
            fclose($stream);
            echo '}';
        }, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Обновить статус сметы
     *
     * @group Estimates
     *
     * @authenticated
     */
    public function updateStatus(UpdateEstimateStatusRequest $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->where('project_id', (int) $project)
            ->firstOrFail();

        $newStatus = $request->validated()['status'];
        $comment = $request->validated()['comment'] ?? null;

        // Проверка прав в зависимости от статуса
        if ($newStatus === 'approved') {
            $this->authorize('approve', $estimateModel);
        } else {
            $this->authorize('update', $estimateModel);
        }

        $estimateModel = $this->statusWorkflow->transition(
            estimate: $estimateModel,
            newStatus: $newStatus,
            actorId: (int) $request->user()->id,
            comment: $comment,
            source: 'admin'
        );

        return AdminResponse::success(
            new EstimateResource($estimateModel),
            $this->getStatusChangeMessage($newStatus)
        );
    }

    /**
     * Получить сообщение об успешном изменении статуса
     */
    private function getStatusChangeMessage(string $status): string
    {
        return match ($status) {
            'draft' => trans_message('estimate.status_changed_to_draft'),
            'in_review' => trans_message('estimate.status_changed_to_review'),
            'approved' => trans_message('estimate.status_changed_to_approved'),
            'cancelled' => trans_message('estimate.status_changed_to_cancelled'),
            default => trans_message('estimate.status_changed'),
        };
    }
}
