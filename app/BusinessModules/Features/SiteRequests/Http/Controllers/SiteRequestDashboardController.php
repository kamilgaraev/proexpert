<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер дашборда для заявок
 */
class SiteRequestDashboardController extends Controller
{
    public function __construct(
        private readonly SiteRequestService $service
    ) {}

    /**
     * Статистика по заявкам
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $stats = $this->service->getStatistics($organizationId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }

    /**
     * Просроченные заявки
     */
    public function overdue(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $overdue = $this->service->getOverdueRequests($organizationId);

            return response()->json([
                'success' => true,
                'data' => SiteRequestResource::collection($overdue),
                'count' => $overdue->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.overdue.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить просроченные заявки',
            ], 500);
        }
    }
}

