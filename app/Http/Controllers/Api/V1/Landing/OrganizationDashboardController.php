<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Services\Landing\OrganizationDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function trans_message;

class OrganizationDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        if (!$organizationId) {
            return LandingResponse::error(trans_message('landing.organization_context_missing'), 400);
        }

        $organization = Organization::find($organizationId);
        if (!$organization) {
            return LandingResponse::error(trans_message('landing.organization_not_found'), 404);
        }

        $dashboardService = app(OrganizationDashboardService::class);
        $data = $dashboardService->getDashboardData($organization);

        return LandingResponse::success($data, trans_message('landing.organization_dashboard.loaded'));
    }
}
