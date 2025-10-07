<?php

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Notifications\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationAnalyticsController extends Controller
{
    protected AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    public function getStats(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'channel', 'from_date', 'to_date']);

        $stats = $this->analyticsService->getStats($filters);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function getStatsByChannel(Request $request): JsonResponse
    {
        $organizationId = $request->query('organization_id');

        $stats = $this->analyticsService->getStatsByChannel($organizationId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

