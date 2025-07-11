<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\CrossOrgWorkReadService;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

class ProjectChildWorksController extends Controller
{
    public function __construct(private readonly CrossOrgWorkReadService $service)
    {
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Получить детализированные работы дочерних организаций по проекту.
     *
     * @param int $projectId
     */
    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        // Авторизация через политику
        Gate::authorize('view', $project);

        $filters = $request->only([
            'child_organization_id',
            'work_type_id',
            'status',
            'date_from',
            'date_to',
            'search',
        ]);

        $perPage = (int)($request->get('per_page', 50));
        $worksPaginator = $this->service->paginateByProject($projectId, $filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $worksPaginator->items(),
            'meta' => [
                'current_page' => $worksPaginator->currentPage(),
                'last_page' => $worksPaginator->lastPage(),
                'per_page' => $worksPaginator->perPage(),
                'total' => $worksPaginator->total(),
            ]
        ]);
    }
} 