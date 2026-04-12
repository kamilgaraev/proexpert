<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Brigades\StoreBrigadeResponseRequest;
use App\Http\Resources\Brigades\BrigadeResponseResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class BrigadeResponseController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function index(): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $responses = BrigadeResponse::query()
            ->with(['request.project', 'request.contractorOrganization', 'brigade.specializations'])
            ->where('brigade_id', $brigade->id)
            ->latest()
            ->get();

        return AdminResponse::success(BrigadeResponseResource::collection($responses));
    }

    public function store(StoreBrigadeResponseRequest $request): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade($request->user());
        $requestModel = BrigadeRequest::query()
            ->whereKey($request->integer('request_id'))
            ->where('status', BrigadeStatuses::REQUEST_OPEN)
            ->firstOrFail();

        $response = BrigadeResponse::updateOrCreate(
            [
                'request_id' => $requestModel->id,
                'brigade_id' => $brigade->id,
            ],
            [
                'cover_message' => $request->input('cover_message'),
                'status' => BrigadeStatuses::RESPONSE_PENDING,
            ]
        );

        $response->load(['request.project', 'request.contractorOrganization', 'brigade.specializations']);

        return AdminResponse::success(new BrigadeResponseResource($response), trans_message('brigades.response_created'), 201);
    }
}
