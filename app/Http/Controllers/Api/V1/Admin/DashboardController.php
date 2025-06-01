<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\Admin\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Получить сводную информацию для дашборда админки
     */
    public function index(Request $request): JsonResponse
    {
        $summary = $this->dashboardService->getSummary($request);
        return response()->json(['success' => true, 'data' => $summary]);
    }

    /**
     * Временной ряд по выбранной метрике
     */
    public function timeseries(Request $request): JsonResponse
    {
        $metric = $request->input('metric', 'users');
        $period = $request->input('period', 'month');
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getTimeseries($metric, $period, $organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Топ-5 сущностей по активности/объёму
     */
    public function topEntities(Request $request): JsonResponse
    {
        $entity = $request->input('entity', 'projects');
        $period = $request->input('period', 'month');
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getTopEntities($entity, $period, $organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * История последних действий/операций
     */
    public function history(Request $request): JsonResponse
    {
        $type = $request->input('type', 'materials');
        $limit = (int)$request->input('limit', 10);
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getHistory($type, $limit, $organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Лимиты и их заполнение
     */
    public function limits(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getLimits($organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }
} 