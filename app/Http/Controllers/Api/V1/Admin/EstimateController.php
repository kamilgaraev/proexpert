<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Http\Requests\Admin\Estimate\CreateEstimateRequest;
use App\Http\Requests\Admin\Estimate\UpdateEstimateRequest;
use App\Http\Requests\Admin\Estimate\UpdateEstimateStatusRequest;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateListResource;
use App\Http\Responses\AdminResponse;
use App\Repositories\EstimateRepository;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateController extends Controller
{
    public function __construct(
        protected EstimateService $estimateService,
        protected EstimateCalculationService $calculationService,
        protected EstimateRepository $repository
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
        
        return AdminResponse::success(
            EstimateListResource::collection($estimates),
            null,
            Response::HTTP_OK,
            [
                'current_page' => $estimates->currentPage(),
                'per_page' => $estimates->perPage(),
                'total' => $estimates->total(),
            ]
        );
    }

    public function store(CreateEstimateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $request->user()->current_organization_id;
        
        $projectId = $request->route('project');
        if (!$projectId) {
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

    public function show(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            $organizationId = $request->user()?->current_organization_id;
        }

        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', (int)$organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        // Загружаем ВСЕ разделы одним запросом
        $allSections = \App\Models\EstimateSection::where('estimate_id', $estimateModel->id)
            ->with([
                'items.workType',
                'items.measurementUnit',
                'items.resources',
                'items.works',
                'items.totals',
                'items.childItems' => function ($query) {
                    $query->with(['workType', 'measurementUnit', 'resources', 'works', 'totals', 'childItems']);
                },
            ])
            ->orderBy('sort_order')
            ->get();
        
        // Группируем разделы по родителю для O(1) поиска (всего O(N))
        $sectionsByParent = $allSections->groupBy('parent_section_id');

        // Оптимизированное построение дерева разделов
        $buildTree = function($parentId = null) use (&$buildTree, $sectionsByParent) {
            $currentLevelSections = $sectionsByParent->get($parentId, collect());
            
            return $currentLevelSections->map(function($section) use ($buildTree) {
                // Внутри раздела группируем позиции для оптимизации
                $allItems = $section->items;
                $itemsByParent = $allItems->groupBy('parent_work_id');

                $buildItemsTree = function($parentItemId = null) use (&$buildItemsTree, $itemsByParent) {
                    $currentLevelItems = $itemsByParent->get($parentItemId, collect());
                    
                    return $currentLevelItems->map(function($item) use ($buildItemsTree) {
                        $item->setRelation('childItems', $buildItemsTree($item->id));
                        return $item;
                    })->values();
                };

                $section->setRelation('items', $buildItemsTree(null));
                $section->setRelation('children', $buildTree($section->id));
                return $section;
            })->values();
        };
        
        // Устанавливаем корневые разделы с построенной иерархией
        $estimateModel->setRelation('sections', $buildTree(null));
        
        // Загружаем позиции без разделов и другие связи
        $itemsWithoutSection = \App\Models\EstimateItem::where('estimate_id', $estimateModel->id)
            ->whereNull('estimate_section_id')
            ->with([
                'workType', 
                'measurementUnit', 
                'resources', 
                'works', 
                'totals',
                'childItems' => function ($q) {
                    $q->with(['workType', 'measurementUnit', 'resources', 'works', 'totals', 'childItems']);
                }
            ])
            ->orderBy('position_number')
            ->get();
        
        // Группируем для оптимизации
        $itemsWithoutSectionByParent = $itemsWithoutSection->groupBy('parent_work_id');

        // Строим иерархию позиций без разделов
        $buildItemsTreeWithoutSection = function($parentItemId = null) use (&$buildItemsTreeWithoutSection, $itemsWithoutSectionByParent) {
            $currentLevelItems = $itemsWithoutSectionByParent->get($parentItemId, collect());

            return $currentLevelItems->map(function($item) use ($buildItemsTreeWithoutSection) {
                $item->setRelation('childItems', $buildItemsTreeWithoutSection($item->id));
                return $item;
            })->values();
        };
        
        $estimateModel->setRelation('items', $buildItemsTreeWithoutSection(null));
        
        $estimateModel->load([
            'project',
            'contract',
            'approvedBy',
        ]);
        
        return AdminResponse::success(new EstimateResource($estimateModel));
    }

    public function update(UpdateEstimateRequest $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
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
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        $itemsCount = $estimateModel->items()->count();
        $sectionsCount = $estimateModel->sections()->count();
        
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
            'versions' => $versions->map(fn($v) => [
                'id' => $v->id,
                'version' => $v->version,
                'created_at' => $v->created_at,
            ]),
            'related' => [
                'project' => $estimateModel->project,
                'contract' => $estimateModel->contract,
            ],
        ]);
    }

    public function structure(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        // Оптимизированная загрузка структуры
        $sections = $estimateModel->sections()
            ->whereNull('parent_section_id')
            ->with([
                'items.workType',
                'items.measurementUnit',
                'items.resources',
                'items.works',
                'items.totals',
                'items.childItems' => function ($q) {
                    $q->with(['workType', 'measurementUnit', 'resources', 'works', 'totals', 'childItems']);
                },
                'children' => function ($query) {
                    $query->with([
                        'items.workType',
                        'items.measurementUnit',
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
                                'items.resources',
                                'items.works',
                                'items.totals',
                                'items.childItems' => function ($q2) {
                                    $q2->with(['workType', 'measurementUnit', 'resources', 'works', 'totals', 'childItems']);
                                },
                            ])->orderBy('sort_order');
                        }
                    ])->orderBy('sort_order');
                }
            ])
            ->orderBy('sort_order')
            ->get();
        
        return AdminResponse::success($sections);
    }

    /**
     * Обновить статус сметы
     * 
     * @group Estimates
     * @authenticated
     */
    public function updateStatus(UpdateEstimateStatusRequest $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $newStatus = $request->validated()['status'];
        $comment = $request->validated()['comment'] ?? null;
        
        // Проверка прав в зависимости от статуса
        if ($newStatus === 'approved') {
            $this->authorize('approve', $estimateModel);
        } else {
            $this->authorize('update', $estimateModel);
        }
        
        // Валидация переходов статусов
        $this->validateStatusTransition($estimateModel, $newStatus);
        
        // Обновление статуса
        $estimateModel->status = $newStatus;
        
        // Если статус "утверждено", сохраняем информацию об утвердившем
        if ($newStatus === 'approved') {
            $estimateModel->approved_by_user_id = $request->user()->id;
            $estimateModel->approved_at = now();
        }
        
        $estimateModel->save();
        
        Log::info('estimate.status_updated', [
            'estimate_id' => $estimateModel->id,
            'old_status' => $estimateModel->getOriginal('status'),
            'new_status' => $newStatus,
            'user_id' => $request->user()->id,
            'comment' => $comment,
        ]);
        
        return AdminResponse::success(
            new EstimateResource($estimateModel->fresh()),
            $this->getStatusChangeMessage($newStatus)
        );
    }

    /**
     * Валидация переходов статусов
     */
    private function validateStatusTransition(Estimate $estimate, string $newStatus): void
    {
        $currentStatus = $estimate->status;
        
        // Разрешенные переходы
        $allowedTransitions = [
            'draft' => ['in_review', 'cancelled'],
            'in_review' => ['draft', 'approved', 'cancelled'],
            'approved' => ['in_review'], // Только для пользователей с правом edit_approved
            'cancelled' => [], // Отмененную смету нельзя изменить
        ];
        
        // Проверка на отмененный статус
        if ($currentStatus === 'cancelled') {
            throw new \DomainException(trans_message('estimate.status_cannot_change_cancelled'));
        }
        
        // Проверка разрешенных переходов
        if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [])) {
            throw new \DomainException(
                __('estimate.status_invalid_transition', ['from' => $currentStatus, 'to' => $newStatus])
            );
        }
        
        // Дополнительная проверка для перехода в "утверждено"
        if ($newStatus === 'approved' && $currentStatus !== 'in_review') {
            throw new \DomainException(trans_message('estimate.status_can_approve_only_in_review'));
        }
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

