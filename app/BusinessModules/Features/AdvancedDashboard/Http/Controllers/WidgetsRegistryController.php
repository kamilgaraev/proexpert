<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetRegistry;

class WidgetsRegistryController extends Controller
{
    protected WidgetRegistry $widgetRegistry;

    public function __construct(WidgetRegistry $widgetRegistry)
    {
        $this->widgetRegistry = $widgetRegistry;
    }

    public function getRegistry(): JsonResponse
    {
        $widgets = $this->widgetRegistry->getWidgetsMetadata();
        $categories = $this->widgetRegistry->getCategoriesMetadata();
        
        return response()->json([
            'success' => true,
            'data' => [
                'widgets' => $widgets,
                'categories' => $categories,
                'stats' => [
                    'total_widgets' => count($widgets),
                    'ready_widgets' => count(array_filter($widgets, fn($w) => $w['is_ready'] ?? false)),
                    'categories_count' => count($categories),
                ],
            ],
        ]);
    }
    
    public function getWidgetInfo(string $widgetId): JsonResponse
    {
        try {
            $widgetType = \App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType::from($widgetId);
            $provider = $this->widgetRegistry->getProvider($widgetType);
            
            if (!$provider) {
            return response()->json([
                'success' => false,
                'message' => 'Widget not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
                'data' => $provider->getMetadata(),
            ]);
        } catch (\ValueError $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid widget ID',
            ], 400);
        }
    }
}
