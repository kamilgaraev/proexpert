<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Resources\Brigades\BrigadeRequestResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class BrigadeRequestController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = BrigadeRequest::query()
            ->with(['project', 'contractorOrganization'])
            ->withCount('responses')
            ->where('status', BrigadeStatuses::REQUEST_OPEN)
            ->latest('published_at')
            ->latest()
            ->get();

        return AdminResponse::success(BrigadeRequestResource::collection($requests));
    }

    public function show(int $requestId): JsonResponse
    {
        $requestModel = BrigadeRequest::query()
            ->with(['project', 'contractorOrganization'])
            ->withCount('responses')
            ->findOrFail($requestId);

        return AdminResponse::success(new BrigadeRequestResource($requestModel));
    }
}
