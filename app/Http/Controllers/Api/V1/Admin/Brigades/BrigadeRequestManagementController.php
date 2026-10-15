<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Brigades\StoreBrigadeRequestRequest;
use App\Http\Resources\Brigades\BrigadeProjectAssignmentResource;
use App\Http\Resources\Brigades\BrigadeRequestResource;
use App\Http\Resources\Brigades\BrigadeResponseResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrigadeRequestManagementController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $requests = BrigadeRequest::query()
            ->with(['project', 'contractorOrganization', 'responses.brigade.specializations'])
            ->withCount('responses')
            ->where('contractor_organization_id', $organizationId)
            ->latest()
            ->get();

        return AdminResponse::success(BrigadeRequestResource::collection($requests));
    }

    public function store(StoreBrigadeRequestRequest $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;

        $model = $this->workflowService->createRequest($request->user(), (int) $organizationId, $request->validated());

        return AdminResponse::success(
            new BrigadeRequestResource($model->load(['project', 'contractorOrganization'])),
            trans_message('brigades.request_created'),
            201
        );
    }

    public function close(Request $request, int $requestId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $model = $this->workflowService->closeRequest($request->user(), (int) $organizationId, $requestId);

        return AdminResponse::success(
            new BrigadeRequestResource($model->load(['project', 'contractorOrganization'])),
            trans_message('brigades.request_closed')
        );
    }

    public function approveResponse(Request $request, int $requestId, int $responseId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;

        $approval = $this->workflowService->approveResponse($request->user(), (int) $organizationId, $requestId, $responseId);

        return AdminResponse::success([
            'response' => new BrigadeResponseResource($approval->response),
            'assignment' => new BrigadeProjectAssignmentResource($approval->assignment),
        ], trans_message('brigades.response_approved'));
    }
}
