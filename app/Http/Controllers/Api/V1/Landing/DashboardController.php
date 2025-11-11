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
     * Главная сводка дашборда по проекту для текущей организации пользователя
     * Возвращает расширенный набор метрик, включая детальный список команды
     * 
     * @param Request $request
     * @query int project_id ID проекта (обязательно)
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        // Валидация обязательного параметра project_id
        $request->validate([
            'project_id' => 'required|integer|min:1',
        ]);
        
        $projectId = (int)$request->query('project_id');

        // Проверяем, что проект принадлежит текущей организации
        $project = \App\Models\Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->first();
            
        if (!$project) {
            return response()->json([
                'success' => false,
                'error' => 'Проект не найден или не принадлежит вашей организации',
            ], 404);
        }

        // Кешируем данные дашборда проекта на 2 минуты
        $cacheKey = "dashboard_data_{$organizationId}_project_{$projectId}";
            
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use ($organizationId, $projectId) {
            return $this->dashboardService->getDashboardData($organizationId, $projectId);
        });

        return response()->json($data);
    }
} 