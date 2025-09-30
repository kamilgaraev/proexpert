<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\OrganizationDashboardService;

class OrganizationDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['error' => 'Организация не определена'], 400);
        }
        
        $organization = \App\Models\Organization::find($organizationId);
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена'], 404);
        }
        $dashboardService = app(OrganizationDashboardService::class);
        $data = $dashboardService->getDashboardData($organization);
        return response()->json($data);
    }
} 