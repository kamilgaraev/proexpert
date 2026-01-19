<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateTemplateService;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateTemplateResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function trans_message;

class EstimateTemplateController extends Controller
{
    public function __construct(
        protected EstimateTemplateService $templateService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        
        $templates = $this->templateService->getTemplates($organizationId, true);
        
        return AdminResponse::success(EstimateTemplateResource::collection($templates));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'estimate_id' => 'required|exists:estimates,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
        
        $estimate = Estimate::findOrFail($validated['estimate_id']);
        $this->authorize('view', $estimate);
        
        $template = $this->templateService->createFromEstimate(
            $estimate,
            $validated['name'],
            $validated['description'] ?? null
        );
        
        return AdminResponse::success(
            new EstimateTemplateResource($template),
            trans_message('estimate.template_created'),
            Response::HTTP_CREATED
        );
    }

    public function show(EstimateTemplate $template): JsonResponse
    {
        return AdminResponse::success(
            new EstimateTemplateResource($template->load(['organization', 'createdBy']))
        );
    }

    public function apply(Request $request, EstimateTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'contract_id' => 'nullable|exists:contracts,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'estimate_date' => 'required|date',
        ]);
        
        $validated['organization_id'] = $request->user()->current_organization_id;
        $validated['type'] = $validated['type'] ?? 'local';
        
        $estimate = $this->templateService->applyTemplate($template, $validated);
        
        return AdminResponse::success(
            new EstimateResource($estimate),
            trans_message('estimate.template_applied'),
            Response::HTTP_CREATED
        );
    }

    public function destroy(EstimateTemplate $template): JsonResponse
    {
        $this->templateService->delete($template);
        
        return AdminResponse::success(null, trans_message('estimate.template_deleted'));
    }

    public function share(EstimateTemplate $template): JsonResponse
    {
        $template = $this->templateService->shareWithHolding($template);
        
        return AdminResponse::success(
            new EstimateTemplateResource($template),
            trans_message('estimate.template_shared')
        );
    }
}

