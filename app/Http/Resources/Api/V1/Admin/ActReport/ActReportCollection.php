<?php

namespace App\Http\Resources\Api\V1\Admin\ActReport;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ActReportCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'reports' => ActReportResource::collection($this->collection),
            'statistics' => [
                'total_reports' => $this->collection->count(),
                'pdf_reports' => $this->collection->where('format', 'pdf')->count(),
                'excel_reports' => $this->collection->where('format', 'excel')->count(),
                'expired_reports' => $this->collection->filter(fn($report) => $report->isExpired())->count(),
                'total_file_size' => $this->formatBytes($this->collection->sum('file_size'))
            ]
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
} 