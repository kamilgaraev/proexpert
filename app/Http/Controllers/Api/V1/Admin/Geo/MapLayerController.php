<?php

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Services\Geo\HeatmapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Response;

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
        try {
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

            $heatmap = $this->heatmapService->generate($organizationId, $metric, $bounds);

            return AdminResponse::success($heatmap);
        } catch (\Throwable $e) {
            Log::error('Error in MapLayerController@getHeatmap', [
                'metric' => $request->input('metric'), 'message' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('dashboard.map_heatmap_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
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
        try {
            $request->validate([
                'north' => 'sometimes|numeric|between:-90,90',
                'south' => 'sometimes|numeric|between:-90,90',
                'east' => 'sometimes|numeric|between:-180,180',
                'west' => 'sometimes|numeric|between:-180,180',
            ]);

            $organizationId = Auth::user()->current_organization_id;
            $bounds = $this->parseBounds($request);

            $density = $this->heatmapService->generateDensityMap($organizationId, $bounds);

            return AdminResponse::success($density);
        } catch (\Throwable $e) {
            Log::error('Error in MapLayerController@getDensity', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('dashboard.map_density_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
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

