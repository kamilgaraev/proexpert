<?php

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Organization;
use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingContractsController extends Controller
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

        $query = Contract::query();
        $this->filterManager->applyHoldingFilters($query, $orgId, $filters);

        $query->with(['organization:id,name,is_holding', 'contractor', 'project:id,name']);

        $contracts = $query->orderBy('date', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $contracts,
        ]);
    }
}

