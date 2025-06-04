<?php

namespace App\Http\Controllers\Api\v1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\OrganizationDashboardService;

class OrganizationDashboardController extends Controller
{
    public function index(Request $request)
    {
        $organization = Auth::user()->organization;
        $dashboardService = new OrganizationDashboardService();
        $data = $dashboardService->getDashboardData($organization);
        return response()->json($data);
    }
} 