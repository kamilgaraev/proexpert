<?php

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\BusinessModules\Core\MultiOrganization\Models\OrganizationMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingDashboardController extends Controller
{
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

        $metrics = OrganizationMetrics::getHoldingMetrics($orgId);

        return response()->json([
            'success' => true,
            'data' => [
                'holding_info' => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'subdomain' => parse_url($request->url(), PHP_URL_HOST),
                ],
                'summary' => $metrics['total'],
                'organizations' => $metrics['by_organization'],
                'last_update' => $metrics['last_update'],
            ],
        ]);
    }
}

