<?php

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Services\Geo\HeatmapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapLayerController extends Controller
{
    public function __construct(
        private HeatmapService $heatmapService
    ) {}

    /**
     * Get heatmap layer
     * GET /api/v1/admin/dashboard/map/layers/heatmap
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getHeatmap(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => 'sometimes|in:budget,problems,activity',
            'north' => 'sometimes|numeric|between:-90,90',
            'south' => 'sometimes|numeric|between:-90,90',
            'east' => 'sometimes|numeric|between:-180,180',
            'west' => 'sometimes|numeric|between:-180,180',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $metric = $request->input('metric', 'budget');
        $bounds = $this->parseBounds($request);

        try {
            $heatmap = $this->heatmapService->generate($organizationId, $metric, $bounds);

            return response()->json([
                'success' => true,
                'data' => $heatmap,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate heatmap: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get density map layer
     * GET /api/v1/admin/dashboard/map/layers/density
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getDensity(Request $request): JsonResponse
    {
        $request->validate([
            'north' => 'sometimes|numeric|between:-90,90',
            'south' => 'sometimes|numeric|between:-90,90',
            'east' => 'sometimes|numeric|between:-180,180',
            'west' => 'sometimes|numeric|between:-180,180',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $bounds = $this->parseBounds($request);

        try {
            $density = $this->heatmapService->generateDensityMap($organizationId, $bounds);

            return response()->json([
                'success' => true,
                'data' => $density,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate density map: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse bounds from request
     */
    private function parseBounds(Request $request): ?array
    {
        $north = $request->input('north');
        $south = $request->input('south');
        $east = $request->input('east');
        $west = $request->input('west');

        if ($north && $south && $east && $west) {
            return compact('north', 'south', 'east', 'west');
        }

        return null;
    }
}

