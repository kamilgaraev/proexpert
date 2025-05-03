<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectCollection;
use App\Services\Projects\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
    }

    public function index(Request $request): JsonResponse
    {
        // В мобильном приложении прораб видит только проекты, связанные с ним
        $projects = $this->projectService->getProjectsForForeman(
            auth()->id(),
            $request->get('per_page', 15),
            $request->get('search'),
            $request->get('status')
        );

        return response()->json(new ProjectCollection($projects));
    }

    public function show(int $id): JsonResponse
    {
        $project = $this->projectService->getProjectForForeman($id, auth()->id());

        return response()->json(new ProjectResource($project));
    }

    public function getMaterialsForProject(int $id, Request $request): JsonResponse
    {
        $materials = $this->projectService->getProjectMaterialsForForeman(
            $id,
            auth()->id(),
            $request->get('per_page', 50),
            $request->get('search')
        );

        return response()->json($materials);
    }

    public function getWorkTypesForProject(int $id, Request $request): JsonResponse
    {
        $workTypes = $this->projectService->getProjectWorkTypesForForeman(
            $id,
            auth()->id(),
            $request->get('per_page', 50),
            $request->get('search')
        );

        return response()->json($workTypes);
    }

    public function getProjectStatistics(int $id): JsonResponse
    {
        $statistics = $this->projectService->getProjectStatisticsForForeman($id, auth()->id());

        return response()->json($statistics);
    }

    public function getSuppliersForProject(int $id, Request $request): JsonResponse
    {
        $suppliers = $this->projectService->getProjectSuppliersForForeman(
            $id,
            auth()->id(),
            $request->get('per_page', 50),
            $request->get('search')
        );

        return response()->json($suppliers);
    }
} 