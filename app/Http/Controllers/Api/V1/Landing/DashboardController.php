<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Landing\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

use function trans_message;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $cacheKey = "dashboard_data_{$organizationId}";

        $data = Cache::remember($cacheKey, 120, function () use ($organizationId) {
            return $this->dashboardService->getDashboardData($organizationId);
        });

        return LandingResponse::success($data, trans_message('landing.dashboard.loaded'));
    }
}
