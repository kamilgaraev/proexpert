<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\CrossOrgWorkReadService;
use App\Services\Project\ProjectContextService;
use App\Models\Project;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

class ProjectChildWorksController extends Controller
{
    public function __construct(
        private readonly CrossOrgWorkReadService $service,
        private readonly ProjectContextService $projectContextService
    ) {
        // Авторизация настроена на уровне роутов через middleware стек
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

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $currentOrgId = $user->current_organization_id;
            $organization = Organization::find($currentOrgId);
            
            if (!$organization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not found',
                ], 404);
            }

            // Проверяем доступ: владелец проекта ИЛИ участник (подрядчик/субподрядчик)
            if (!$this->projectContextService->canOrganizationAccessProject($project, $organization)) {
                Log::warning('[ProjectChildWorksController] Access denied', [
                    'user_id' => $user->id,
                    'project_id' => $projectId,
                    'project_org_id' => $project->organization_id,
                    'user_current_org_id' => $currentOrgId,
                    'reason' => 'Organization is not project owner or participant',
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. You are not a participant of this project.',
                ], 403);
            }

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

            Log::info('[ProjectChildWorksController] paginator meta', [
                'total' => $worksPaginator->total(),
                'current_page' => $worksPaginator->currentPage(),
            ]);

            $items = collect($worksPaginator->items())->map(function ($row) {
                return [
                    'id' => $row->id,
                    'project_id' => $row->project_id,
                    'child_organization' => [
                        'id' => $row->child_organization_id,
                        'name' => $row->child_organization_name,
                    ],
                    'work_type' => [
                        'id' => $row->work_type_id,
                        'name' => $row->work_type_name,
                        'measurement_unit' => $row->measurement_unit,
                    ],
                    'quantity' => $row->quantity,
                    'price' => $row->price,
                    'total_amount' => $row->total_amount,
                    'completion_date' => $row->completion_date,
                    'status' => $row->status,
                    'notes' => $row->notes,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $items,
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