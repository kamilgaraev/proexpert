<?php

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Organization;
use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use App\BusinessModules\Core\MultiOrganization\Services\ContextAwareOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingProjectsController extends Controller
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('current_organization_id');
        $org = Organization::findOrFail($orgId);

        if (!$org->is_holding) {
            return response()->json([
                'success' => false,
                'error' => 'Access restricted to holding organizations'
            ], 403);
        }

        $filters = $request->get('filters', []);
        $perPage = $request->get('per_page', 50);

        $query = Project::query();
        $this->filterManager->applyHoldingFilters($query, $orgId, $filters);

        $query->with('organization:id,name,is_holding');

        $projects = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $projects,
        ]);
    }

    public function show(Request $request, int $projectId): JsonResponse
    {
        $orgId = $request->attributes->get('current_organization_id');
        $org = Organization::findOrFail($orgId);

        if (!$org->is_holding) {
            return response()->json([
                'success' => false,
                'error' => 'Access restricted'
            ], 403);
        }

        $scope = app(ContextAwareOrganizationScope::class);
        $allowedOrgIds = $scope->getOrganizationScope($orgId);

        $project = Project::with(['organization', 'contracts', 'organizations'])
            ->whereIn('organization_id', $allowedOrgIds)
            ->findOrFail($projectId);

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }
}

