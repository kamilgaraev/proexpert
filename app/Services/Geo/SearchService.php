<?php

namespace App\Services\Geo;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class SearchService
{
    /**
     * Search projects by query string
     * 
     * @param int $organizationId
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function search(int $organizationId, string $query, int $limit = 20): array
    {
        $query = trim($query);

        if (empty($query)) {
            return ['results' => []];
        }

        // Search in project name and address
        $projects = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($q) use ($query) {
                $q->where('name', 'ILIKE', "%{$query}%")
                    ->orWhere('address', 'ILIKE', "%{$query}%")
                    ->orWhere('description', 'ILIKE', "%{$query}%");
            })
            ->limit($limit)
            ->get();

        $results = $projects->map(function ($project) {
            return [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
                'status' => $project->status,
                'budget' => (float) $project->budget_amount,
            ];
        });

        return [
            'query' => $query,
            'count' => $results->count(),
            'results' => $results->values()->all(),
        ];
    }

    /**
     * Search projects near a location
     * 
     * @param int $organizationId
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @param int $limit
     * @return array
     */
    public function searchNearby(
        int $organizationId,
        float $latitude,
        float $longitude,
        float $radiusKm = 10,
        int $limit = 20
    ): array {
        // Use Haversine formula for distance calculation
        // This works without PostGIS
        $projects = DB::select("
            SELECT 
                id,
                name,
                address,
                latitude,
                longitude,
                status,
                budget_amount,
                (6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                )) AS distance
            FROM projects
            WHERE organization_id = ?
                AND latitude IS NOT NULL
                AND longitude IS NOT NULL
            HAVING distance < ?
            ORDER BY distance
            LIMIT ?
        ", [$latitude, $longitude, $latitude, $organizationId, $radiusKm, $limit]);

        $results = collect($projects)->map(function ($project) {
            return [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
                'status' => $project->status,
                'budget' => (float) $project->budget_amount,
                'distance_km' => round($project->distance, 2),
            ];
        });

        return [
            'center' => [
                'lat' => $latitude,
                'lng' => $longitude,
            ],
            'radius_km' => $radiusKm,
            'count' => $results->count(),
            'results' => $results->all(),
        ];
    }

    /**
     * Search by address component (structured search)
     * 
     * @param int $organizationId
     * @param array $filters ['city', 'region', 'street', etc.]
     * @param int $limit
     * @return array
     */
    public function searchByComponents(int $organizationId, array $filters, int $limit = 20): array
    {
        $query = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->join('project_addresses', 'projects.id', '=', 'project_addresses.project_id');

        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->where("project_addresses.{$field}", 'ILIKE', "%{$value}%");
            }
        }

        $projects = $query->select('projects.*')
            ->limit($limit)
            ->get();

        $results = $projects->map(function ($project) {
            return [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
                'status' => $project->status,
                'budget' => (float) $project->budget_amount,
            ];
        });

        return [
            'filters' => $filters,
            'count' => $results->count(),
            'results' => $results->values()->all(),
        ];
    }

    /**
     * Auto-suggest addresses as user types
     * 
     * @param int $organizationId
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function suggest(int $organizationId, string $query, int $limit = 10): array
    {
        $query = trim($query);

        if (strlen($query) < 2) {
            return ['suggestions' => []];
        }

        $projects = Project::where('organization_id', $organizationId)
            ->where(function ($q) use ($query) {
                $q->where('name', 'ILIKE', "%{$query}%")
                    ->orWhere('address', 'ILIKE', "%{$query}%");
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('id', 'name', 'address', 'latitude', 'longitude')
            ->limit($limit)
            ->get();

        $suggestions = $projects->map(function ($project) {
            return [
                'id' => $project->id,
                'label' => $project->name,
                'sublabel' => $project->address,
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
            ];
        });

        return [
            'query' => $query,
            'suggestions' => $suggestions->values()->all(),
        ];
    }
}

