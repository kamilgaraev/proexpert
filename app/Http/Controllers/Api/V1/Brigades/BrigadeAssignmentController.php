<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Brigades\BrigadeProjectAssignmentResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class BrigadeAssignmentController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function index(): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $assignments = $brigade->assignments()->with(['project', 'contractorOrganization', 'brigade.specializations'])->latest()->get();

        return AdminResponse::success(BrigadeProjectAssignmentResource::collection($assignments));
    }
}
