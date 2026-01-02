<?php

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Geo\TileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapTileController extends Controller
{
    public function __construct(
        private TileService $tileService
    ) {}

    /**
     * Get map tile data
     * GET /api/v1/admin/dashboard/map/tiles/{z}/{x}/{y}
     * 
     * @param Request $request
     * @param int $z Zoom level
     * @param int $x Tile X coordinate
     * @param int $y Tile Y coordinate
     * @return JsonResponse
     */
    public function getTile(Request $request, int $z, int $x, int $y): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        // Validate zoom level
        if ($z < 0 || $z > 20) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid zoom level. Must be between 0 and 20.',
            ], 400);
        }

        $options = [
            'layer' => $request->input('layer', 'projects'),
            'filters' => $request->input('filters', []),
        ];

        try {
            $tile = $this->tileService->getTile($organizationId, $z, $x, $y, $options);

            return response()->json([
                'success' => true,
                'data' => $tile,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate tile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all projects for the map (for simpler implementation)
     * GET /api/v1/admin/dashboard/map/projects
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getProjects(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $filters = $request->input('filters', []);

        try {
            // Получаем проекты напрямую из БД без тайловой системы
            $query = Project::where('organization_id', $organizationId)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude');

            // Применяем фильтры
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['health'])) {
                // Health фильтр требует расчета EVM метрик
                // Пока пропускаем, можно добавить позже
            }

            if (isset($filters['budget_min'])) {
                $query->where('budget_amount', '>=', $filters['budget_min']);
            }

            if (isset($filters['budget_max'])) {
                $query->where('budget_amount', '<=', $filters['budget_max']);
            }

            $projects = $query->get();

            // Преобразуем в GeoJSON
            // В GeoJSON формат координат: [longitude, latitude]
            $features = [];
            foreach ($projects as $project) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $project->longitude, (float) $project->latitude],
                    ],
                    'properties' => [
                        'id' => $project->id,
                        'name' => $project->name,
                        'address' => $project->address,
                        'status' => $project->status,
                        'budget' => (float) ($project->budget_amount ?? 0),
                        'start_date' => $project->start_date?->format('Y-m-d'),
                        'end_date' => $project->end_date?->format('Y-m-d'),
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'type' => 'FeatureCollection',
                    'features' => $features,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get projects: ' . $e->getMessage(),
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

