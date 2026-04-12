<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Brigades\StoreBrigadeMemberRequest;
use App\Http\Resources\Brigades\BrigadeMemberResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class BrigadeMemberController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function index(): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());

        return AdminResponse::success(BrigadeMemberResource::collection($brigade->members()->latest()->get()));
    }

    public function store(StoreBrigadeMemberRequest $request): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade($request->user());
        $member = $brigade->members()->create($request->validated());

        return AdminResponse::success(new BrigadeMemberResource($member), trans_message('brigades.member_created'), 201);
    }

    public function update(StoreBrigadeMemberRequest $request, int $memberId): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade($request->user());
        $member = $brigade->members()->whereKey($memberId)->firstOrFail();
        $member->update($request->validated());

        return AdminResponse::success(new BrigadeMemberResource($member), trans_message('brigades.member_updated'));
    }

    public function destroy(int $memberId): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $member = $brigade->members()->whereKey($memberId)->firstOrFail();
        $member->delete();

        return AdminResponse::success(null, trans_message('brigades.member_deleted'));
    }
}
