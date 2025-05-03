<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\ProjectService;
use App\Http\Requests\Api\V1\Admin\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Admin\Project\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Admin\Project\ProjectResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;

class ProjectController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $request->query('per_page', 15);
        $projects = $this->projectService->getProjectsForCurrentOrg($request, (int)$perPage);
        return ProjectResource::collection($projects);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): ProjectResource
    {
        $project = $this->projectService->createProject($request->validated(), $request);
        return new ProjectResource($project);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id): ProjectResource | JsonResponse
    {
        $project = $this->projectService->findProjectByIdForCurrentOrg((int)$id, $request);
        if (!$project) {
            return response()->json(['message' => 'Project not found in your organization'], 404);
        }
        return new ProjectResource($project->load('users'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, string $id): ProjectResource | JsonResponse
    {
        try {
            $project = $this->projectService->updateProject((int)$id, $request->validated(), $request);
            if (!$project) {
                return response()->json(['message' => 'Project not found or update failed'], 404);
            }
            return new ProjectResource($project);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error updating project', ['id' => $id, 'exception' => $e]);
            return response()->json(['message' => 'Internal server error while updating project.'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->projectService->deleteProject((int)$id, $request);
            if (!$success) {
                return response()->json(['message' => 'Project not found or delete failed'], 404);
            }
            return response()->json(null, 204);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error deleting project', ['id' => $id, 'exception' => $e]);
            return response()->json(['message' => 'Internal server error while deleting project.'], 500);
        }
    }

    /**
     * Назначить прораба на проект.
     */
    public function assignForeman(Request $request, string $projectId, string $userId): JsonResponse
    {
        if (Gate::denies('manage-project-assignments')) {
            return response()->json(['message' => 'Forbidden' ], 403);
        }

        try {
            $success = $this->projectService->assignForemanToProject((int)$projectId, (int)$userId, $request);
            if (!$success) {
                return response()->json(['message' => 'Failed to assign foreman. Project or User not found, or user is not a foreman in the organization.'], 404);
            }
            return response()->json(['message' => 'Foreman assigned successfully.']);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error assigning foreman to project', ['project_id' => $projectId, 'user_id' => $userId, 'exception' => $e]);
            return response()->json(['message' => 'Internal server error during assignment.'], 500);
        }
    }

    /**
     * Открепить прораба от проекта.
     */
    public function detachForeman(Request $request, string $projectId, string $userId): JsonResponse
    {
        if (Gate::denies('manage-project-assignments')) {
            return response()->json(['message' => 'Forbidden' ], 403);
        }

        try {
            $success = $this->projectService->detachForemanFromProject((int)$projectId, (int)$userId, $request);
            if (!$success) {
                return response()->json(['message' => 'Failed to detach foreman. Project or User not found, or user was not assigned.'], 404);
            }
            return response()->json(['message' => 'Foreman detached successfully.']);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error detaching foreman from project', ['project_id' => $projectId, 'user_id' => $userId, 'exception' => $e]);
            return response()->json(['message' => 'Internal server error during detachment.'], 500);
        }
    }

    public function statistics(int $id): JsonResponse
    {
        $statistics = $this->projectService->getProjectStatistics($id);

        return response()->json($statistics);
    }

    public function getProjectMaterials(int $id, Request $request): JsonResponse
    {
        $materials = $this->projectService->getProjectMaterials(
            $id,
            $request->get('per_page', 15),
            $request->get('search'),
            $request->get('sort_by', 'created_at'),
            $request->get('sort_direction', 'desc')
        );

        return response()->json($materials);
    }

    public function getProjectWorkTypes(int $id, Request $request): JsonResponse
    {
        $workTypes = $this->projectService->getProjectWorkTypes(
            $id,
            $request->get('per_page', 15),
            $request->get('search'),
            $request->get('sort_by', 'created_at'),
            $request->get('sort_direction', 'desc')
        );

        return response()->json($workTypes);
    }
} 