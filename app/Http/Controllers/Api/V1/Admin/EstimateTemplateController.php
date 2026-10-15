<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Estimate\ApplyEstimateTemplateRequest;
use App\Http\Requests\Admin\Estimate\StoreEstimateTemplateRequest;
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

    public function store(StoreEstimateTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $estimate = Estimate::findOrFail($validated['estimate_id']);
        
        $template = $this->templateService->createFromEstimate(
            $estimate,
            $validated['name'],
            $request->user(),
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

    public function apply(ApplyEstimateTemplateRequest $request, EstimateTemplate $template): JsonResponse
    {
        $estimate = $this->templateService->applyTemplate($template, $request->validated(), $request->user());
        
        return AdminResponse::success(
            new EstimateResource($estimate),
            trans_message('estimate.template_applied'),
            Response::HTTP_CREATED
        );
    }

    public function destroy(Request $request, EstimateTemplate $template): JsonResponse
    {
        $this->templateService->delete($template, $request->user());
        
        return AdminResponse::success(null, trans_message('estimate.template_deleted'));
    }

    public function share(Request $request, EstimateTemplate $template): JsonResponse
    {
        $template = $this->templateService->shareWithHolding($template, $request->user());
        
        return AdminResponse::success(
            new EstimateTemplateResource($template),
            trans_message('estimate.template_shared')
        );
    }
}

