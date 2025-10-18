<?php

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingFilterController extends Controller
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {}

    public function getFilterOptions(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('current_organization_id');
        $org = Organization::findOrFail($orgId);

        if (!$org->is_holding) {
            return response()->json([
                'success' => false,
                'error' => 'Not a holding organization'
            ], 403);
        }

        $options = $this->filterManager->getFilterOptions($orgId);

        return response()->json([
            'success' => true,
            'data' => $options,
        ]);
    }
}

