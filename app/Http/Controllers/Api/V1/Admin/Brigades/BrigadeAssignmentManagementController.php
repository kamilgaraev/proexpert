<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Brigades\UpdateBrigadeAssignmentStatusRequest;
use App\Http\Resources\Brigades\BrigadeProjectAssignmentResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrigadeAssignmentManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $query = BrigadeProjectAssignment::query()
            ->with(['brigade.specializations', 'project', 'contractorOrganization'])
            ->where('contractor_organization_id', $organizationId);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        return AdminResponse::success(BrigadeProjectAssignmentResource::collection($query->latest()->get()));
    }

    public function update(UpdateBrigadeAssignmentStatusRequest $request, int $assignmentId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $assignment = BrigadeProjectAssignment::query()
            ->where('contractor_organization_id', $organizationId)
            ->findOrFail($assignmentId);

        $assignment->update($request->validated());

        return AdminResponse::success(
            new BrigadeProjectAssignmentResource($assignment->load(['brigade.specializations', 'project', 'contractorOrganization'])),
            trans_message('brigades.assignment_updated')
        );
    }
}
