<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\CrossOrgWorkReadService;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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
        Log::info('[ProjectChildWorksController] incoming request', [
            'project_id' => $projectId,
            'query' => $request->query->all(),
            'user_id' => $request->user()?->id,
        ]);

        try {
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

            Log::debug('[ProjectChildWorksController] applying filters', [
                'filters' => $filters,
                'per_page' => $perPage,
            ]);

            $worksPaginator = $this->service->paginateByProject($projectId, $filters, $perPage);

            Log::info('[ProjectChildWorksController] paginator meta', [
                'total' => $worksPaginator->total(),
                'current_page' => $worksPaginator->currentPage(),
            ]);

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
        } catch (\Throwable $e) {
            Log::error('[ProjectChildWorksController] error', [
                'project_id' => $projectId,
                'message' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);

            return response()->json([
                'success' => false,
                'message' => app()->environment('production') ? 'Internal Server Error' : $e->getMessage(),
            ], 500);
        }
    }
} 