<?php

namespace App\BusinessModules\Addons\AIEstimates\Services;

use App\Models\Estimate;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectHistoryAnalysisService
{
    public function findSimilarProjects(int $organizationId, ?float $area, ?string $buildingType): array
    {
        if (!$area) {
            return [
                'count' => 0,
                'average_sections' => [],
                'typical_volumes' => [],
            ];
        }

        // Ищем проекты с похожей площадью (±20%)
        $minArea = $area * 0.8;
        $maxArea = $area * 1.2;

        $similarProjects = Project::where('organization_id', $organizationId)
            ->whereBetween('site_area_m2', [$minArea, $maxArea])
            ->when($buildingType, function ($query, $buildingType) {
                $query->where('additional_info->building_type', $buildingType);
            })
            ->with(['estimates' => function ($query) {
                $query->whereNotNull('approved_at')
                      ->with(['sections', 'items']);
            }])
            ->limit(10)
            ->get();

        if ($similarProjects->isEmpty()) {
            return [
                'count' => 0,
                'average_sections' => [],
                'typical_volumes' => [],
            ];
        }

        return [
            'count' => $similarProjects->count(),
            'average_sections' => $this->extractTypicalSections($similarProjects),
            'typical_volumes' => $this->calculateTypicalVolumes($similarProjects, $area),
        ];
    }

    protected function extractTypicalSections($projects): array
    {
        $sectionNames = [];

        foreach ($projects as $project) {
            foreach ($project->estimates as $estimate) {
                foreach ($estimate->sections as $section) {
                    $sectionNames[] = $section->name;
                }
            }
        }

        // Считаем частоту каждого раздела
        $frequency = array_count_values($sectionNames);
        arsort($frequency);

        // Возвращаем топ-10 самых частых разделов
        return array_slice(array_keys($frequency), 0, 10);
    }

    protected function calculateTypicalVolumes($projects, float $targetArea): array
    {
        $volumes = [];

        foreach ($projects as $project) {
            $projectArea = $project->site_area_m2 ?: 1;
            
            foreach ($project->estimates as $estimate) {
                foreach ($estimate->items as $item) {
                    $key = $item->name;
                    
                    if (!isset($volumes[$key])) {
                        $volumes[$key] = [
                            'values' => [],
                            'unit' => $item->unit,
                        ];
                    }
                    
                    // Нормализуем объем на 1 м²
                    $normalizedVolume = $item->quantity / $projectArea;
                    $volumes[$key]['values'][] = $normalizedVolume;
                }
            }
        }

        // Рассчитываем средние объемы для целевой площади
        $typicalVolumes = [];
        foreach ($volumes as $workName => $data) {
            $avgPerSqm = array_sum($data['values']) / count($data['values']);
            $typicalVolumes[$workName] = [
                'quantity' => round($avgPerSqm * $targetArea, 2),
                'unit' => $data['unit'],
            ];
        }

        return $typicalVolumes;
    }
}
