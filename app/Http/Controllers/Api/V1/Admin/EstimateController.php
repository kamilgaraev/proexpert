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
use App\Repositories\EstimateRepository;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        
        return response()->json([
            'data' => EstimateListResource::collection($estimates),
            'meta' => [
                'current_page' => $estimates->currentPage(),
                'per_page' => $estimates->perPage(),
                'total' => $estimates->total(),
            ]
        ]);
    }

    public function store(CreateEstimateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['organization_id'] = $request->user()->current_organization_id;
        
        $projectId = $request->route('project');
        if (!$projectId) {
            return response()->json([
                'message' => 'Смета должна быть создана в контексте проекта'
            ], 422);
        }
        
        $data['project_id'] = $projectId;
        
        $estimate = $this->estimateService->create($data);
        
        return response()->json([
            'data' => new EstimateResource($estimate),
            'message' => 'Смета успешно создана'
        ], 201);
    }

    public function show(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('view', $estimateModel);
        
        // Оптимизированная загрузка: используем рекурсивную загрузку с ограничением глубины
        $estimateModel->load([
            // Загружаем только корневые разделы с их вложенностью
            'sections' => function ($query) {
                $query->whereNull('parent_section_id')
                    ->with([
                        'items.workType',
                        'items.measurementUnit',
                        'items.resources',
                        // Загружаем дочерние разделы до 3 уровней
                        'children' => function ($q) {
                            $q->with([
                                'items.workType',
                                'items.measurementUnit',
                                'items.resources',
                                'children' => function ($q2) {
                                    $q2->with([
                                        'items.workType',
                                        'items.measurementUnit',
                                        'items.resources',
                                    ])->orderBy('sort_order');
                                }
                            ])->orderBy('sort_order');
                        }
                    ])
                    ->orderBy('sort_order');
            },
            // Загружаем позиции без разделов отдельно
            'items' => function ($query) {
                $query->whereNull('estimate_section_id')
                    ->with(['workType', 'measurementUnit', 'resources'])
                    ->orderBy('position_number');
            },
            // Дополнительные связи
            'project',
            'contract',
            'approvedBy',
        ]);
        
        return response()->json([
            'data' => new EstimateResource($estimateModel)
        ]);
    }

    public function update(UpdateEstimateRequest $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $estimate = $this->estimateService->update($estimateModel, $request->validated());
        
        return response()->json([
            'data' => new EstimateResource($estimate),
            'message' => 'Смета успешно обновлена'
        ]);
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
            
            return response()->json([
                'message' => 'Смета успешно удалена'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
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
        
        return response()->json([
            'data' => new EstimateResource($newEstimate),
            'message' => 'Смета успешно дублирована'
        ], 201);
    }

    public function recalculate(Request $request, $project, int $estimate): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimateModel = Estimate::where('id', $estimate)
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        
        $this->authorize('update', $estimateModel);
        
        $totals = $this->calculationService->recalculateAll($estimateModel);
        
        return response()->json([
            'data' => $totals,
            'message' => 'Смета пересчитана'
        ]);
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
        
        return response()->json([
            'data' => [
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
            ]
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
                'children' => function ($query) {
                    $query->with([
                        'items.workType',
                        'items.measurementUnit',
                        'children' => function ($q) {
                            $q->with([
                                'items.workType',
                                'items.measurementUnit',
                            ])->orderBy('sort_order');
                        }
                    ])->orderBy('sort_order');
                }
            ])
            ->orderBy('sort_order')
            ->get();
        
        return response()->json([
            'data' => $sections
        ]);
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
            $estimateModel->approved_by = auth()->id();
            $estimateModel->approved_at = now();
        }
        
        $estimateModel->save();
        
        \Log::info('estimate.status_updated', [
            'estimate_id' => $estimateModel->id,
            'old_status' => $estimateModel->getOriginal('status'),
            'new_status' => $newStatus,
            'user_id' => auth()->id(),
            'comment' => $comment,
        ]);
        
        return response()->json([
            'data' => new EstimateResource($estimateModel->fresh()),
            'message' => $this->getStatusChangeMessage($newStatus),
        ]);
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
            throw new \DomainException('Нельзя изменить статус отмененной сметы');
        }
        
        // Проверка разрешенных переходов
        if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [])) {
            throw new \DomainException(
                "Недопустимый переход статуса из '{$currentStatus}' в '{$newStatus}'"
            );
        }
        
        // Дополнительная проверка для перехода в "утверждено"
        if ($newStatus === 'approved' && $currentStatus !== 'in_review') {
            throw new \DomainException('Утвердить можно только смету со статусом "На проверке"');
        }
    }

    /**
     * Получить сообщение об успешном изменении статуса
     */
    private function getStatusChangeMessage(string $status): string
    {
        return match ($status) {
            'draft' => 'Смета возвращена в черновик',
            'in_review' => 'Смета отправлена на проверку',
            'approved' => 'Смета успешно утверждена',
            'cancelled' => 'Смета отменена',
            default => 'Статус сметы успешно изменен',
        };
    }
}

