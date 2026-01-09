<?php

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Geo\TileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Response;

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
        try {
            $organizationId = Auth::user()->current_organization_id;

            // Validate zoom level
            if ($z < 0 || $z > 20) {
                return AdminResponse::error('Invalid zoom level. Must be between 0 and 20.', Response::HTTP_BAD_REQUEST);
            }

            $options = [
                'layer' => $request->input('layer', 'projects'),
                'filters' => $request->input('filters', []),
            ];

            $tile = $this->tileService->getTile($organizationId, $z, $x, $y, $options);

            return AdminResponse::success($tile);
        } catch (\Throwable $e) {
            Log::error('Error in MapTileController@getTile', [
                'z' => $z, 'x' => $x, 'y' => $y, 'message' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('dashboard.map_tile_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
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
        try {
            $organizationId = Auth::user()->current_organization_id;
            $filters = $request->input('filters', []);

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

            return AdminResponse::success([
                'type' => 'FeatureCollection',
                'features' => $features,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in MapTileController@getProjects', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('dashboard.map_projects_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
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

