<?php

namespace App\Http\Resources\Api\V1\Admin\SiteRequest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SiteRequestCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->transform(function ($siteRequest) {
                return new SiteRequestResource($siteRequest);
            }),
            'meta' => [
                'total' => $this->total(),
                'per_page' => $this->perPage(),
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
            ],
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
            'summary' => $this->getSummary(),
        ];
    }
    
    /**
     * Получить сводную информацию о заявках
     */
    private function getSummary(): array
    {
        $collection = $this->collection;
        
        return [
            'total_requests' => $collection->count(),
            'by_status' => [
                'pending' => $collection->where('status', 'pending')->count(),
                'in_progress' => $collection->where('status', 'in_progress')->count(),
                'completed' => $collection->where('status', 'completed')->count(),
                'cancelled' => $collection->where('status', 'cancelled')->count(),
                'on_hold' => $collection->where('status', 'on_hold')->count(),
            ],
            'by_priority' => [
                'low' => $collection->where('priority', 'low')->count(),
                'medium' => $collection->where('priority', 'medium')->count(),
                'high' => $collection->where('priority', 'high')->count(),
                'urgent' => $collection->where('priority', 'urgent')->count(),
            ],
            'by_type' => [
                'material_request' => $collection->where('request_type', 'material_request')->count(),
                'equipment_request' => $collection->where('request_type', 'equipment_request')->count(),
                'personnel_request' => $collection->where('request_type', 'personnel_request')->count(),
                'service_request' => $collection->where('request_type', 'service_request')->count(),
                'other' => $collection->where('request_type', 'other')->count(),
            ],
            'personnel_requests' => [
                'total' => $collection->where('request_type', 'personnel_request')->count(),
                'by_personnel_type' => $this->getPersonnelTypeSummary($collection),
                'total_personnel_needed' => $collection->where('request_type', 'personnel_request')
                    ->sum('personnel_count'),
                'average_hourly_rate' => $collection->where('request_type', 'personnel_request')
                    ->where('hourly_rate', '>', 0)
                    ->avg('hourly_rate'),
            ],
            'overdue_count' => $collection->filter(function ($request) {
                return $request->required_date && 
                       now()->isAfter($request->required_date) && 
                       !in_array($request->status, ['completed', 'cancelled']);
            })->count(),
        ];
    }
    
    /**
     * Получить сводку по типам персонала
     */
    private function getPersonnelTypeSummary($collection): array
    {
        $personnelRequests = $collection->where('request_type', 'personnel_request');
        
        $summary = [];
        foreach ($personnelRequests as $request) {
            if ($request->personnel_type) {
                $type = $request->personnel_type;
                if (!isset($summary[$type])) {
                    $summary[$type] = [
                        'count' => 0,
                        'total_personnel' => 0,
                        'average_rate' => 0,
                    ];
                }
                $summary[$type]['count']++;
                $summary[$type]['total_personnel'] += $request->personnel_count ?? 0;
            }
        }
        
        // Вычисляем средние ставки
        foreach ($summary as $type => &$data) {
            $typeRequests = $personnelRequests->where('personnel_type', $type)
                ->where('hourly_rate', '>', 0);
            $data['average_rate'] = $typeRequests->avg('hourly_rate') ?? 0;
        }
        
        return $summary;
    }
}