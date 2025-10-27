<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Http\Requests\Admin\Estimate\CreateEstimateRequest;
use App\Http\Requests\Admin\Estimate\UpdateEstimateRequest;
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

    public function show($project, Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);
        
        return response()->json([
            'data' => new EstimateResource($estimate->load(['sections.items', 'items.resources']))
        ]);
    }

    public function update(UpdateEstimateRequest $request, $project, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);
        
        $estimate = $this->estimateService->update($estimate, $request->validated());
        
        return response()->json([
            'data' => new EstimateResource($estimate),
            'message' => 'Смета успешно обновлена'
        ]);
    }

    public function destroy($project, Estimate $estimate): JsonResponse
    {
        $this->authorize('delete', $estimate);
        
        try {
            $this->estimateService->delete($estimate);
            
            return response()->json([
                'message' => 'Смета успешно удалена'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function duplicate($project, Estimate $estimate, Request $request): JsonResponse
    {
        $this->authorize('create', Estimate::class);
        
        $newEstimate = $this->estimateService->duplicate(
            $estimate,
            $request->input('number'),
            $request->input('name')
        );
        
        return response()->json([
            'data' => new EstimateResource($newEstimate),
            'message' => 'Смета успешно дублирована'
        ], 201);
    }

    public function recalculate($project, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);
        
        $totals = $this->calculationService->recalculateAll($estimate);
        
        return response()->json([
            'data' => $totals,
            'message' => 'Смета пересчитана'
        ]);
    }

    public function dashboard($project, Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);
        
        $itemsCount = $estimate->items()->count();
        $sectionsCount = $estimate->sections()->count();
        
        $structure = $this->calculationService->getEstimateStructure($estimate);
        
        $versions = $this->repository->getVersions($estimate);
        
        return response()->json([
            'data' => [
                'estimate' => new EstimateResource($estimate),
                'statistics' => [
                    'items_count' => $itemsCount,
                    'sections_count' => $sectionsCount,
                    'total_amount' => $estimate->total_amount,
                    'total_amount_with_vat' => $estimate->total_amount_with_vat,
                ],
                'cost_structure' => $structure,
                'versions' => $versions->map(fn($v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'created_at' => $v->created_at,
                ]),
                'related' => [
                    'project' => $estimate->project,
                    'contract' => $estimate->contract,
                ],
            ]
        ]);
    }

    public function structure($project, Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);
        
        $sections = $estimate->sections()
            ->with(['children', 'items.workType', 'items.measurementUnit'])
            ->whereNull('parent_section_id')
            ->get();
        
        return response()->json([
            'data' => $sections
        ]);
    }
}

