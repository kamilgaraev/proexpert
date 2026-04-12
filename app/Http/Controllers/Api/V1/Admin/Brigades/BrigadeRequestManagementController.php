<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
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

        $model = BrigadeRequest::create([
            ...$request->validated(),
            'contractor_organization_id' => $organizationId,
            'status' => BrigadeStatuses::REQUEST_OPEN,
            'published_at' => now(),
        ]);

        return AdminResponse::success(
            new BrigadeRequestResource($model->load(['project', 'contractorOrganization'])),
            trans_message('brigades.request_created'),
            201
        );
    }

    public function close(Request $request, int $requestId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $model = BrigadeRequest::query()
            ->where('contractor_organization_id', $organizationId)
            ->findOrFail($requestId);

        $model->update(['status' => BrigadeStatuses::REQUEST_CLOSED]);

        return AdminResponse::success(
            new BrigadeRequestResource($model->load(['project', 'contractorOrganization'])),
            trans_message('brigades.request_closed')
        );
    }

    public function approveResponse(Request $request, int $requestId, int $responseId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $response = BrigadeResponse::query()
            ->with(['request', 'brigade.specializations', 'request.project', 'request.contractorOrganization'])
            ->where('request_id', $requestId)
            ->whereKey($responseId)
            ->firstOrFail();

        if ($response->request->contractor_organization_id !== (int) $organizationId) {
            return AdminResponse::error(trans_message('brigades.forbidden'), 403);
        }

        $response->update(['status' => BrigadeStatuses::RESPONSE_APPROVED]);
        $response->request->update(['status' => BrigadeStatuses::REQUEST_IN_REVIEW]);
        $assignment = $this->workflowService->createAssignmentFromResponse($response);

        return AdminResponse::success([
            'response' => new BrigadeResponseResource($response),
            'assignment' => new BrigadeProjectAssignmentResource($assignment->load(['project', 'contractorOrganization', 'brigade.specializations'])),
        ], trans_message('brigades.response_approved'));
    }
}
