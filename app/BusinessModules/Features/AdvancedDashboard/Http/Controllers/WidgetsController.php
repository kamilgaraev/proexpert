<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetService;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetRegistry;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class WidgetsController extends Controller
{
    protected WidgetService $widgetService;
    protected WidgetRegistry $widgetRegistry;

    public function __construct(WidgetService $widgetService, WidgetRegistry $widgetRegistry)
    {
        $this->widgetService = $widgetService;
        $this->widgetRegistry = $widgetRegistry;
    }

    public function getData(Request $request, string $type): JsonResponse
    {
        try {
            $widgetType = WidgetType::from($type);
        } catch (\ValueError $e) {
            return response()->json([
                'success' => false,
                'message' => "Invalid widget type: {$type}",
            ], 400);
        }

        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required',
            ], 400);
        }

        $widgetRequest = new WidgetDataRequest(
            widgetType: $widgetType,
            organizationId: $organizationId,
            userId: $request->user()->id,
            projectId: $request->input('project_id'),
            contractId: $request->input('contract_id'),
            employeeId: $request->input('employee_id'),
            from: $request->has('from') ? Carbon::parse($request->input('from')) : null,
            to: $request->has('to') ? Carbon::parse($request->input('to')) : null,
            filters: $request->input('filters', []),
            options: $request->input('options', []),
        );

        try {
            $response = $this->widgetService->getWidgetData($widgetType, $widgetRequest);
            return response()->json($response->toArray());
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching widget data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getBatch(Request $request): JsonResponse
    {
        $request->validate([
            'widgets' => 'required|array',
            'widgets.*.type' => 'required|string',
        ]);

        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required',
            ], 400);
        }
        
        $userId = $request->user()->id;

        $results = [];

        foreach ($request->input('widgets') as $widgetConfig) {
            try {
                $widgetType = WidgetType::from($widgetConfig['type']);

                $widgetRequest = new WidgetDataRequest(
                    widgetType: $widgetType,
                    organizationId: $organizationId,
                    userId: $userId,
                    projectId: $widgetConfig['project_id'] ?? null,
                    contractId: $widgetConfig['contract_id'] ?? null,
                    from: isset($widgetConfig['from']) ? Carbon::parse($widgetConfig['from']) : null,
                    to: isset($widgetConfig['to']) ? Carbon::parse($widgetConfig['to']) : null,
                    filters: $widgetConfig['filters'] ?? [],
                    options: $widgetConfig['options'] ?? [],
                );

                $response = $this->widgetService->getWidgetData($widgetType, $widgetRequest);

                $results[] = $response->toArray();
            } catch (\ValueError $e) {
                $results[] = [
                    'widget_type' => $widgetConfig['type'] ?? 'unknown',
                    'success' => false,
                    'data' => [],
                    'message' => "Invalid widget type: {$widgetConfig['type']}",
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'widget_type' => $widgetConfig['type'] ?? 'unknown',
                    'success' => false,
                    'data' => [],
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    public function getMetadata(string $type): JsonResponse
    {
        try {
            $widgetType = WidgetType::from($type);
            $provider = $this->widgetRegistry->getProvider($widgetType);

            if (!$provider) {
                return response()->json([
                    'success' => false,
                    'message' => "Widget provider not found for type: {$type}",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'metadata' => $provider->getMetadata(),
            ]);
        } catch (\ValueError $e) {
            return response()->json([
                'success' => false,
                'message' => "Invalid widget type: {$type}",
            ], 400);
        }
    }

    public function getRegistry(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'widgets' => $this->widgetRegistry->getWidgetsMetadata(),
            'categories' => $this->widgetRegistry->getCategoriesMetadata(),
        ]);
    }

    public function getCategories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'categories' => $this->widgetRegistry->getCategoriesMetadata(),
        ]);
    }
}

