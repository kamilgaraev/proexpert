<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Services\ContextAwareOrganizationScope;
use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class HoldingProjectsController extends Controller
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $orgId = (int) $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return AdminResponse::error(trans_message('holding.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $filters = (array) $request->get('filters', []);
            $perPage = (int) $request->get('per_page', 50);

            $query = Project::query();
            $this->filterManager->applyHoldingFilters($query, $orgId, $filters);

            $query->with('organization:id,name,is_holding');

            $projects = $query->orderByDesc('created_at')->paginate($perPage);

            return AdminResponse::paginated(
                $projects->items(),
                [
                    'current_page' => $projects->currentPage(),
                    'from' => $projects->firstItem(),
                    'last_page' => $projects->lastPage(),
                    'path' => $projects->path(),
                    'per_page' => $projects->perPage(),
                    'to' => $projects->lastItem(),
                    'total' => $projects->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $projects->url(1),
                    'last' => $projects->url($projects->lastPage()),
                    'prev' => $projects->previousPageUrl(),
                    'next' => $projects->nextPageUrl(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('[HoldingProjectsController.index] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'filters' => $request->get('filters', []),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('holding.projects_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function show(Request $request, int $projectId): JsonResponse
    {
        try {
            $orgId = (int) $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return AdminResponse::error(trans_message('holding.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $scope = app(ContextAwareOrganizationScope::class);
            $allowedOrgIds = $scope->getOrganizationScope($orgId);

            $project = Project::with(['organization', 'contracts', 'organizations'])
                ->whereIn('organization_id', $allowedOrgIds)
                ->findOrFail($projectId);

            return AdminResponse::success($project);
        } catch (\Throwable $e) {
            Log::error('[HoldingProjectsController.show] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $projectId,
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('holding.project_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
