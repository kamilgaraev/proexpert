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
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $dashboardService = new OrganizationDashboardService();
        $data = $dashboardService->getDashboardData($organization);
        return response()->json($data);
    }
} 