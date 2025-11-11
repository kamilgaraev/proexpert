<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\DashboardService;

class DashboardController extends Controller
{
    private DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Главная сводка дашборда для текущей организации пользователя.
     * Возвращает расширенный набор метрик, включая детальный список команды.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        // Кешируем данные дашборда на 2 минуты
        $cacheKey = "dashboard_data_{$organizationId}";
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use ($organizationId) {
            return $this->dashboardService->getDashboardData($organizationId);
        });

        return response()->json($data);
    }
} 